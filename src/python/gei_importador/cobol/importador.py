from __future__ import annotations

import json
import csv
from collections.abc import Callable
from dataclasses import asdict, dataclass, is_dataclass
from datetime import date, datetime
from hashlib import sha256
from pathlib import Path
from typing import Any

from gei_importador.cobol import inquilino, propietar
from gei_importador.cobol.plan_maestros import (
    PlanificadorMaestrosCobol,
    RepositorioMaestrosPostgresql,
    guardar_plan_maestros,
)
from gei_importador.config import Config
from gei_importador.database import conectar
from gei_importador.errores import ArchivoCobolFaltanteError, ModoNoSoportadoError
from gei_importador.repositories.importaciones import RepositorioArchivos
from gei_importador.resultado import ResultadoImportacion, ResumenArchivo


ARCHIVOS_PRIMERA_FASE = (propietar.NOMBRE_ARCHIVO, inquilino.NOMBRE_ARCHIVO)
TIPOS_POR_ARCHIVO = {
    propietar.NOMBRE_ARCHIVO: "propietario",
    inquilino.NOMBRE_ARCHIVO: "inquilino",
}

EstadoExistente = dict[str, Any]
ProveedorExistentes = Callable[[str, str, str], EstadoExistente]


@dataclass(frozen=True)
class RegistroIncremental:
    archivo: str
    tipo: str
    cuenta: str
    clave_origen: str
    hash_registro: str
    estado: str
    motivo: str

    def to_dict(self) -> dict[str, str]:
        return {
            "archivo": self.archivo,
            "tipo": self.tipo,
            "cuenta": self.cuenta,
            "clave_origen": self.clave_origen,
            "hash_registro": self.hash_registro,
            "estado": self.estado,
            "motivo": self.motivo,
        }


@dataclass(frozen=True)
class ConflictoIncremental:
    archivo: str
    tipo: str
    cuenta: str
    clave_origen: str
    hash_actual: str
    hashes_existentes: list[str]
    payload_actual: dict[str, Any]
    payloads_existentes: list[dict[str, Any]]
    contenido_original_actual: str | None
    contenido_original_existente: str | None
    campos_que_cambiaron: list[dict[str, Any]]
    causa_probable: str
    posible_causa: str

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


@dataclass(frozen=True)
class StagingNoPresente:
    archivo: str
    tipo: str
    cuenta: str
    clave_origen: str
    fecha_importacion: str | None
    estado: str
    contenido_original: str | None
    payload_existente: dict[str, Any] | None
    hash_existente: str | None

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


class ImportadorCobol:
    def __init__(
        self,
        repositorio: RepositorioArchivos,
        config: Config | None = None,
        proveedor_existentes: ProveedorExistentes | None = None,
    ) -> None:
        self.repositorio = repositorio
        self.config = config
        self.proveedor_existentes = proveedor_existentes
        self._advertencias_emitidas: set[str] = set()
        self._cache_existentes: dict[tuple[str, str], dict[str, EstadoExistente]] = {}
        self._reporte_incremental: list[RegistroIncremental] = []
        self._conflictos_incrementales: list[ConflictoIncremental] = []
        self._staging_no_presente: list[StagingNoPresente] = []
        self._claves_actuales: dict[tuple[str, str], set[str]] = {}

    def importar(
        self,
        modo: str,
        planificar_maestros: bool = False,
    ) -> ResultadoImportacion:
        if modo != "solo-validar":
            raise ModoNoSoportadoError(
                "La primera fase solo implementa el modo solo-validar"
            )

        resultado = ResultadoImportacion(
            repositorio_id=self.repositorio.repositorio_id,
            modo=modo,
            repositorio_path=self.repositorio.path,
            escritura_postgresql=False,
        )

        if self.repositorio.es_compatibilidad_legacy:
            resultado.advertencias.append(
                "Se uso liquidaciones/cobol como fallback legacy porque no existe "
                "una carpeta por repositorio_id."
            )

        self._validar_existencia()

        resumen_propietar, _propietarios = propietar.validar_archivo(
            self.repositorio.archivo_cobol(propietar.NOMBRE_ARCHIVO)
        )
        self._clasificar_incremental(
            resumen_propietar,
            propietar.NOMBRE_ARCHIVO,
            _propietarios,
            resultado,
        )
        resultado.archivos[propietar.NOMBRE_ARCHIVO] = resumen_propietar

        resumen_inquilino, _inquilinos = inquilino.validar_archivo(
            self.repositorio.archivo_cobol(inquilino.NOMBRE_ARCHIVO)
        )
        self._clasificar_incremental(
            resumen_inquilino,
            inquilino.NOMBRE_ARCHIVO,
            _inquilinos,
            resultado,
        )
        resultado.archivos[inquilino.NOMBRE_ARCHIVO] = resumen_inquilino
        if planificar_maestros:
            self._planificar_maestros(resultado, _propietarios, _inquilinos)
        self._detectar_staging_no_presente(resultado)
        self._guardar_reporte_incremental(resultado)

        return resultado

    def _validar_existencia(self) -> None:
        faltantes = [
            nombre
            for nombre in ARCHIVOS_PRIMERA_FASE
            if not self.repositorio.archivo_cobol(nombre).is_file()
        ]

        if faltantes:
            raise ArchivoCobolFaltanteError(
                "Faltan archivos COBOL requeridos: " + ", ".join(faltantes)
            )

    def _clasificar_incremental(
        self,
        resumen: ResumenArchivo,
        archivo: str,
        registros: list[Any],
        resultado: ResultadoImportacion,
    ) -> None:
        tipo = TIPOS_POR_ARCHIVO[archivo]
        self._claves_actuales.setdefault((archivo, tipo), set())

        for registro in registros:
            clave = str(getattr(registro, "cuenta"))
            hash_registro = hash_registro_normalizado(registro)
            existentes = _asegurar_estado_existente(
                self._existentes(archivo, tipo, clave, resultado)
            )
            self._claves_actuales[(archivo, tipo)].add(clave)

            if not existentes["hashes"]:
                resumen.nuevos += 1
                self._reporte_incremental.append(
                    RegistroIncremental(
                        archivo=archivo,
                        tipo=tipo,
                        cuenta=clave,
                        clave_origen=clave,
                        hash_registro=hash_registro,
                        estado="NUEVO",
                        motivo=(
                            "No existe una fila previa en web_importaciones_registros "
                            "para la clave funcional."
                        ),
                    )
                )
                continue

            if hash_registro in existentes["hashes"]:
                resumen.omitidos_ya_importados += 1
                self._reporte_incremental.append(
                    RegistroIncremental(
                        archivo=archivo,
                        tipo=tipo,
                        cuenta=clave,
                        clave_origen=clave,
                        hash_registro=hash_registro,
                        estado="OMITIDO_YA_IMPORTADO",
                        motivo=(
                            "Existe una fila previa en web_importaciones_registros "
                            "con la misma clave funcional y el mismo hash normalizado."
                        ),
                    )
                )
                continue

            resumen.conflictos += 1
            self._conflictos_incrementales.append(
                self._construir_conflicto(
                    archivo,
                    tipo,
                    clave,
                    hash_registro,
                    registro,
                    existentes,
                )
            )
            self._reporte_incremental.append(
                RegistroIncremental(
                    archivo=archivo,
                    tipo=tipo,
                    cuenta=clave,
                    clave_origen=clave,
                    hash_registro=hash_registro,
                    estado="CONFLICTO_CAMBIO_ORIGEN",
                    motivo=(
                        "Existe una fila previa con la misma clave funcional, pero "
                        "el hash normalizado difiere. No se actualiza automaticamente "
                        "en modo solo-validar."
                    ),
                )
            )

        resumen.metadata["clasificacion_incremental"] = {
            "clave_funcional": "web_archivo + web_tipo + cuenta",
            "hash": "sha256(json_normalizado(payload_interpretado))",
            "modo": "solo-validar",
        }

    def _existentes(
        self,
        archivo: str,
        tipo: str,
        clave: str,
        resultado: ResultadoImportacion,
    ) -> EstadoExistente:
        if self.proveedor_existentes is not None:
            try:
                return self.proveedor_existentes(archivo, tipo, clave)
            except Exception as exc:
                self._advertir_una_vez(
                    resultado,
                    "error_proveedor_incremental",
                    "No se pudo consultar el proveedor incremental "
                    f"({type(exc).__name__}: {exc}). Los registros validos se "
                    "consideran NUEVO en esta ejecucion solo-validar.",
                )

                return {"hashes": set()}

        if self.config is None or not self.config.pgdatabase:
            self._advertir_una_vez(
                resultado,
                "sin_postgresql",
                "Sin conexion PostgreSQL configurada: la clasificacion incremental "
                "no pudo comparar contra web_importaciones_registros y considera "
                "los registros validos como NUEVO.",
            )

            return {"hashes": set()}

        try:
            cache_key = (archivo, tipo)
            if cache_key not in self._cache_existentes:
                self._cache_existentes[cache_key] = buscar_registros_existentes(
                    self.config,
                    archivo,
                    tipo,
                )

            return self._cache_existentes[cache_key].get(clave, {"hashes": set()})
        except Exception as exc:
            mensaje = (
                "No se pudo consultar web_importaciones_registros para clasificacion "
                f"incremental ({type(exc).__name__}: {exc}). Los registros validos "
                "se consideran NUEVO en esta ejecucion solo-validar."
            )
            self._advertir_una_vez(resultado, "error_consulta_incremental", mensaje)

            return {"hashes": set()}

    def _advertir_una_vez(
        self,
        resultado: ResultadoImportacion,
        codigo: str,
        mensaje: str,
    ) -> None:
        if codigo in self._advertencias_emitidas:
            return

        resultado.advertencias.append(mensaje)
        self._advertencias_emitidas.add(codigo)

    def _planificar_maestros(
        self,
        resultado: ResultadoImportacion,
        propietarios: list[Any],
        inquilinos: list[Any],
    ) -> None:
        if self.config is None or not self.config.pgdatabase:
            self._advertir_una_vez(
                resultado,
                "sin_postgresql_plan_maestros",
                "Sin conexion PostgreSQL configurada: no se puede comparar el plan "
                "de maestros contra tablas finales.",
            )
            return

        try:
            repositorio = RepositorioMaestrosPostgresql(self.config)
            plan = PlanificadorMaestrosCobol(repositorio).planificar(
                propietarios,
                inquilinos,
            )
            resultado.extra["plan_maestros"] = guardar_plan_maestros(
                plan,
                self._directorio_reporte(),
            )
        except Exception as exc:
            self._advertir_una_vez(
                resultado,
                "error_plan_maestros",
                "No se pudo generar el plan de maestros "
                f"({type(exc).__name__}: {exc}).",
            )

    def _construir_conflicto(
        self,
        archivo: str,
        tipo: str,
        clave: str,
        hash_actual: str,
        registro: Any,
        existentes: EstadoExistente,
    ) -> ConflictoIncremental:
        payload_actual = _normalizar(
            registro.to_dict() if hasattr(registro, "to_dict") else registro
        )
        registros_existentes = list(existentes.get("registros", []))
        payloads_existentes = [
            _normalizar(_decodificar_payload(item.get("payload")))
            for item in registros_existentes
            if item.get("payload") is not None
        ]
        hashes_existentes = sorted(str(h) for h in existentes.get("hashes", set()))
        campos = _comparar_payloads(payload_actual, payloads_existentes)
        causa = _clasificar_causa_conflicto(tipo, campos, registros_existentes)

        return ConflictoIncremental(
            archivo=archivo,
            tipo=tipo,
            cuenta=clave,
            clave_origen=clave,
            hash_actual=hash_actual,
            hashes_existentes=hashes_existentes,
            payload_actual=payload_actual,
            payloads_existentes=[
                p if isinstance(p, dict) else {"valor": p}
                for p in payloads_existentes
            ],
            contenido_original_actual=getattr(registro, "raw", None),
            contenido_original_existente=None,
            campos_que_cambiaron=campos,
            causa_probable=causa,
            posible_causa=_descripcion_causa(causa),
        )

    def _detectar_staging_no_presente(self, resultado: ResultadoImportacion) -> None:
        for archivo in ARCHIVOS_PRIMERA_FASE:
            tipo = TIPOS_POR_ARCHIVO[archivo]
            cache_key = (archivo, tipo)
            actuales = self._claves_actuales.get(cache_key, set())

            try:
                if cache_key not in self._cache_existentes:
                    if self.proveedor_existentes is not None:
                        continue
                    if self.config is None or not self.config.pgdatabase:
                        continue

                    self._cache_existentes[cache_key] = buscar_registros_existentes(
                        self.config,
                        archivo,
                        tipo,
                    )

                for clave, existente in self._cache_existentes.get(cache_key, {}).items():
                    if clave in actuales:
                        continue

                    for item in list(existente.get("registros", [])):
                        payload = _normalizar(_decodificar_payload(item.get("payload")))
                        self._staging_no_presente.append(
                            StagingNoPresente(
                                archivo=archivo,
                                tipo=tipo,
                                cuenta=str(clave),
                                clave_origen=str(clave),
                                fecha_importacion=_string_o_none(item.get("created_at")),
                                estado="NO_PRESENTE_EN_ARCHIVO_ACTUAL",
                                contenido_original=None,
                                payload_existente=payload
                                if isinstance(payload, dict)
                                else {"valor": payload},
                                hash_existente=_string_o_none(item.get("hash")),
                            )
                        )
            except Exception as exc:
                self._advertir_una_vez(
                    resultado,
                    f"error_staging_no_presente_{archivo}",
                    "No se pudo detectar staging no presente en archivo actual para "
                    f"{archivo} ({type(exc).__name__}: {exc}).",
                )

    def _guardar_reporte_incremental(self, resultado: ResultadoImportacion) -> None:
        if not self._reporte_incremental:
            return

        directorio = self._directorio_reporte()
        directorio.mkdir(parents=True, exist_ok=True)
        ruta_json = directorio / "reporte_incremental.json"
        ruta_csv = directorio / "reporte_incremental.csv"
        filas = [registro.to_dict() for registro in self._reporte_incremental]

        ruta_json.write_text(
            json.dumps(filas, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )

        with ruta_csv.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(
                handle,
                fieldnames=[
                    "archivo",
                    "tipo",
                    "cuenta",
                    "clave_origen",
                    "hash_registro",
                    "estado",
                    "motivo",
                ],
            )
            writer.writeheader()
            writer.writerows(filas)

        resultado.extra["reporte_incremental"] = {
            "json": str(ruta_json),
            "csv": str(ruta_csv),
            "registros": len(filas),
        }
        self._guardar_conflictos(directorio, resultado)
        self._guardar_staging_no_presente(directorio, resultado)

    def _guardar_conflictos(
        self,
        directorio: Path,
        resultado: ResultadoImportacion,
    ) -> None:
        ruta_json = directorio / "conflictos_detalle.json"
        ruta_csv = directorio / "conflictos_detalle.csv"
        filas = [conflicto.to_dict() for conflicto in self._conflictos_incrementales]

        ruta_json.write_text(
            json.dumps(filas, ensure_ascii=False, indent=2, default=str),
            encoding="utf-8",
        )

        with ruta_csv.open("w", encoding="utf-8", newline="") as handle:
            fieldnames = [
                "archivo",
                "tipo",
                "cuenta",
                "clave_origen",
                "hash_actual",
                "hashes_existentes",
                "campos_que_cambiaron",
                "causa_probable",
                "posible_causa",
            ]
            writer = csv.DictWriter(handle, fieldnames=fieldnames)
            writer.writeheader()
            for fila in filas:
                writer.writerow(
                    {
                        "archivo": fila["archivo"],
                        "tipo": fila["tipo"],
                        "cuenta": fila["cuenta"],
                        "clave_origen": fila["clave_origen"],
                        "hash_actual": fila["hash_actual"],
                        "hashes_existentes": json.dumps(
                            fila["hashes_existentes"],
                            ensure_ascii=False,
                            default=str,
                        ),
                        "campos_que_cambiaron": json.dumps(
                            fila["campos_que_cambiaron"],
                            ensure_ascii=False,
                            default=str,
                        ),
                        "causa_probable": fila["causa_probable"],
                        "posible_causa": fila["posible_causa"],
                    }
                )

        resultado.extra["conflictos_detalle"] = {
            "json": str(ruta_json),
            "csv": str(ruta_csv),
            "registros": len(filas),
        }

    def _guardar_staging_no_presente(
        self,
        directorio: Path,
        resultado: ResultadoImportacion,
    ) -> None:
        ruta_json = directorio / "staging_no_presente_en_archivo.json"
        ruta_csv = directorio / "staging_no_presente_en_archivo.csv"
        filas = [item.to_dict() for item in self._staging_no_presente]

        ruta_json.write_text(
            json.dumps(filas, ensure_ascii=False, indent=2, default=str),
            encoding="utf-8",
        )

        with ruta_csv.open("w", encoding="utf-8", newline="") as handle:
            fieldnames = [
                "archivo",
                "tipo",
                "cuenta",
                "clave_origen",
                "fecha_importacion",
                "estado",
                "hash_existente",
            ]
            writer = csv.DictWriter(handle, fieldnames=fieldnames)
            writer.writeheader()
            for fila in filas:
                writer.writerow({key: fila.get(key) for key in fieldnames})

        resultado.extra["staging_no_presente_en_archivo"] = {
            "json": str(ruta_json),
            "csv": str(ruta_csv),
            "registros": len(filas),
        }

    def _directorio_reporte(self) -> Path:
        base = (
            self.config.importador_base_dir
            if self.config is not None
            else self.repositorio.path
        )

        return (
            base
            / "salida"
            / "importar-cobol"
            / str(self.repositorio.repositorio_id)
        )


def hash_registro_normalizado(registro: Any) -> str:
    payload = registro.to_dict() if hasattr(registro, "to_dict") else _normalizar(registro)
    normalizado = json.dumps(payload, ensure_ascii=False, sort_keys=True, default=str)

    return sha256(normalizado.encode("utf-8")).hexdigest()


def buscar_registros_existentes(
    config: Config,
    archivo: str,
    tipo: str,
) -> dict[str, EstadoExistente]:
    registros_por_clave: dict[str, EstadoExistente] = {}
    with conectar(config) as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT web_id, web_linea, web_clave, web_payload, created_at, updated_at
                FROM web_importaciones_registros
                WHERE web_archivo = %s
                  AND web_tipo = %s
                """,
                (archivo, tipo),
            )
            for web_id, linea, clave, payload, created_at, updated_at in cur.fetchall():
                clave_texto = str(clave)
                hash_payload = hash_payload_normalizado(payload)
                estado = registros_por_clave.setdefault(
                    clave_texto,
                    {"hashes": set(), "registros": []},
                )
                estado["hashes"].add(hash_payload)
                estado["registros"].append(
                    {
                        "web_id": web_id,
                        "linea": linea,
                        "clave": clave_texto,
                        "payload": payload,
                        "hash": hash_payload,
                        "created_at": created_at,
                        "updated_at": updated_at,
                    }
                )

    return registros_por_clave


def buscar_hashes_existentes(
    config: Config,
    archivo: str,
    tipo: str,
) -> dict[str, set[str]]:
    return {
        clave: set(estado.get("hashes", set()))
        for clave, estado in buscar_registros_existentes(config, archivo, tipo).items()
    }


def hash_payload_normalizado(payload: Any) -> str:
    normalizado = json.dumps(
        _normalizar(_decodificar_payload(payload)),
        ensure_ascii=False,
        sort_keys=True,
        default=str,
    )

    return sha256(normalizado.encode("utf-8")).hexdigest()


def _asegurar_estado_existente(existentes: EstadoExistente) -> EstadoExistente:
    hashes = existentes.get("hashes", set())
    if isinstance(hashes, list):
        hashes = set(str(h) for h in hashes)
    elif not isinstance(hashes, set):
        hashes = set(hashes) if hashes else set()

    return {
        **existentes,
        "hashes": hashes,
        "registros": list(existentes.get("registros", [])),
    }


def _decodificar_payload(payload: Any) -> Any:
    if isinstance(payload, str):
        try:
            return json.loads(payload)
        except json.JSONDecodeError:
            return payload

    return payload


def _comparar_payloads(
    payload_actual: Any,
    payloads_existentes: list[Any],
) -> list[dict[str, Any]]:
    if not isinstance(payload_actual, dict) or not payloads_existentes:
        return []

    existente = next((p for p in payloads_existentes if isinstance(p, dict)), None)
    if existente is None:
        return []

    campos: list[dict[str, Any]] = []
    for campo in sorted(set(payload_actual) | set(existente)):
        actual = payload_actual.get(campo)
        anterior = existente.get(campo)
        if actual == anterior:
            continue

        campos.append(
            {
                "campo": campo,
                "valor_actual": actual,
                "valor_existente": anterior,
                "normalizacion": _tipo_normalizacion(actual, anterior),
            }
        )

    return campos


def _tipo_normalizacion(actual: Any, anterior: Any) -> str | None:
    if isinstance(actual, str) and isinstance(anterior, str):
        actual_normalizado = " ".join(actual.strip().split()).upper()
        anterior_normalizado = " ".join(anterior.strip().split()).upper()
        if actual_normalizado == anterior_normalizado:
            return "ESPACIOS_MAYUSCULAS"

    return None


def _clasificar_causa_conflicto(
    tipo: str,
    campos: list[dict[str, Any]],
    registros_existentes: list[dict[str, Any]],
) -> str:
    if len(registros_existentes) > 1:
        return "DUPLICADO_EN_STAGING"

    nombres = {str(campo["campo"]) for campo in campos}
    if campos and all(campo.get("normalizacion") for campo in campos):
        return "ESPACIOS_ENCODING_FORMATO"

    if tipo == "inquilino" and nombres.intersection(
        {
            "cuenta_propietario",
            "domicilio_inmueble",
            "fecha_contrato",
            "fecha_vencimiento",
            "fecha_inicio",
            "fecha_baja",
        }
    ):
        return "CLAVE_ORIGEN_INSUFICIENTE"

    if nombres == {"omitido_por_baja_antigua"}:
        return "PARSER_VERSION_DISTINTA"

    if campos:
        return "CAMBIO_REAL_FUENTE"

    return "SIN_DETERMINAR"


def _descripcion_causa(causa: str) -> str:
    descripciones = {
        "NORMALIZACION_DISTINTA": "El valor interpretado difiere por normalizacion.",
        "CAMBIO_REAL_FUENTE": "El payload actual y el payload registrado tienen campos funcionales distintos.",
        "CLAVE_ORIGEN_INSUFICIENTE": (
            "La cuenta sola no distingue versiones del registro; se recomienda "
            "evaluar contrato, inmueble y fechas como parte de la clave."
        ),
        "DUPLICADO_EN_STAGING": "Hay mas de una fila de staging para la misma clave funcional.",
        "PARSER_VERSION_DISTINTA": "La diferencia parece provenir de una regla de parser versionada.",
        "ESPACIOS_ENCODING_FORMATO": "La diferencia parece limitarse a espacios, mayusculas o formato textual.",
        "SIN_DETERMINAR": "No hay evidencia suficiente en el staging para explicar la diferencia.",
    }

    return descripciones.get(causa, descripciones["SIN_DETERMINAR"])


def _string_o_none(valor: Any) -> str | None:
    return None if valor is None else str(valor)


def _normalizar(value: Any) -> Any:
    if is_dataclass(value):
        return _normalizar(asdict(value))

    if isinstance(value, dict):
        return {str(k): _normalizar(v) for k, v in value.items()}

    if isinstance(value, list):
        return [_normalizar(v) for v in value]

    if isinstance(value, (date, datetime)):
        return value.isoformat()

    return value


def resumen_error(
    repositorio_id: int,
    modo: str,
    repositorio_path,
    mensaje: str,
) -> ResultadoImportacion:
    resultado = ResultadoImportacion(
        repositorio_id=repositorio_id,
        modo=modo,
        repositorio_path=repositorio_path,
        escritura_postgresql=False,
    )
    for nombre in ARCHIVOS_PRIMERA_FASE:
        resumen = ResumenArchivo(nombre=nombre)
        resumen.errores = 1
        resultado.archivos[nombre] = resumen
    resultado.advertencias.append(mensaje)
    return resultado
