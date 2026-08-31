from __future__ import annotations

import json
import os
from hashlib import sha256
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from gei_importador.cobol import inquilino, propietar
from gei_importador.cobol import cuentas
from gei_importador.config import Config
from gei_importador.database import conectar
from gei_importador.errores import ArchivoCobolFaltanteError
from gei_importador.liquidaciones import parser as liquidaciones_parser
from gei_importador.resultado import ResumenArchivo


COBOL_ESPERADOS = (
    propietar.NOMBRE_ARCHIVO,
    inquilino.NOMBRE_ARCHIVO,
    "CTACTEPRO.TXT",
    "INQCTACTE.TXT",
)
LIQUIDACIONES_ESPERADAS = (
    "liquida.sf.txt",
    "liquida.st.txt",
    "liquidb.sf.txt",
    "liquidb.st.txt",
    "dailoc.SF.txt",
    "dailoc2.SF.txt",
)


@dataclass
class LoteInterpretado:
    resumenes: dict[str, ResumenArchivo] = field(default_factory=dict)
    registros: list[dict[str, Any]] = field(default_factory=list)
    advertencias: list[dict[str, Any]] = field(default_factory=list)
    errores: list[dict[str, Any]] = field(default_factory=list)

    @property
    def registros_leidos(self) -> int:
        return sum(r.registros for r in self.resumenes.values())

    @property
    def registros_validos(self) -> int:
        return sum(r.validos for r in self.resumenes.values())


def _entrada(config: Config) -> tuple[Path, Path]:
    base = config.importador_base_dir / "entrada"
    return base / "cobol", base / "liquidaciones"


def _validar_existencia(cobol_dir: Path, liquidaciones_dir: Path) -> None:
    faltantes = [
        str(cobol_dir / nombre)
        for nombre in COBOL_ESPERADOS
        if not (cobol_dir / nombre).is_file()
    ]
    faltantes += [
        str(liquidaciones_dir / nombre)
        for nombre in LIQUIDACIONES_ESPERADAS
        if not (liquidaciones_dir / nombre).is_file()
    ]
    if faltantes:
        raise ArchivoCobolFaltanteError(
            "Faltan archivos requeridos: " + ", ".join(faltantes)
        )


def interpretar_lote(config: Config) -> LoteInterpretado:
    cobol_dir, liquidaciones_dir = _entrada(config)
    _validar_existencia(cobol_dir, liquidaciones_dir)

    lote = LoteInterpretado()

    resumen, registros = propietar.validar_archivo(cobol_dir / propietar.NOMBRE_ARCHIVO)
    lote.resumenes[resumen.nombre] = resumen
    lote.registros.extend(
        {
            "archivo": resumen.nombre,
            "linea": idx,
            "tipo": "propietario",
            "clave": registro.cuenta,
            "periodo": None,
            "payload": registro.to_dict(),
        }
        for idx, registro in enumerate(registros, 1)
    )

    resumen, registros_inq = inquilino.validar_archivo(cobol_dir / inquilino.NOMBRE_ARCHIVO)
    lote.resumenes[resumen.nombre] = resumen
    lote.registros.extend(
        {
            "archivo": resumen.nombre,
            "linea": idx,
            "tipo": "inquilino",
            "clave": registro.cuenta,
            "periodo": None,
            "payload": registro.to_dict(),
        }
        for idx, registro in enumerate(registros_inq, 1)
    )

    for nombre in ("CTACTEPRO.TXT", "INQCTACTE.TXT"):
        resumen, movimientos = cuentas.validar_archivo(cobol_dir / nombre, nombre)
        lote.resumenes[resumen.nombre] = resumen
        lote.registros.extend(
            {
                "archivo": resumen.nombre,
                "linea": movimiento.linea,
                "tipo": "cuenta_propietario"
                if nombre == "CTACTEPRO.TXT"
                else "cuenta_inquilino",
                "clave": movimiento.cuenta,
                "periodo": movimiento.periodo,
                "payload": movimiento.to_dict(),
            }
            for movimiento in movimientos
        )

    for nombre in LIQUIDACIONES_ESPERADAS:
        resumen, liquidaciones = liquidaciones_parser.validar_archivo(
            liquidaciones_dir / nombre
        )
        lote.resumenes[resumen.nombre] = resumen
        lote.registros.extend(
            {
                "archivo": resumen.nombre,
                "linea": liquidacion.linea,
                "tipo": f"liquidacion_{liquidacion.tipo}",
                "clave": liquidacion.cuenta,
                "periodo": liquidacion.periodo,
                "payload": liquidacion.to_dict(),
            }
            for liquidacion in liquidaciones
        )

    for resumen in lote.resumenes.values():
        for error in resumen.errores_detalle:
            lote.errores.append(error.to_dict())

    _detectar_desincronizacion(lote)

    return lote


def _detectar_desincronizacion(lote: LoteInterpretado) -> None:
    periodos_liquidaciones = {
        registro["periodo"]
        for registro in lote.registros
        if str(registro["tipo"]).startswith("liquidacion_") and registro["periodo"]
    }
    periodos_cuentas = {
        registro["periodo"]
        for registro in lote.registros
        if str(registro["tipo"]).startswith("cuenta_") and registro["periodo"]
    }
    if periodos_liquidaciones and periodos_cuentas and not (
        periodos_liquidaciones & periodos_cuentas
    ):
        lote.advertencias.append(
            {
                "codigo": "periodos_desincronizados",
                "mensaje": "Los periodos detectados en liquidaciones y cuentas corrientes no coinciden.",
                "datos": {
                    "liquidaciones": sorted(periodos_liquidaciones),
                    "cuentas": sorted(periodos_cuentas)[:20],
                },
            }
        )


def validar(config: Config) -> dict[str, Any]:
    lote = interpretar_lote(config)
    return _resultado("validado", None, lote)


def importar(config: Config) -> dict[str, Any]:
    lote = interpretar_lote(config)
    importacion_id = _persistir_lote(config, lote)
    return _resultado("importado", importacion_id, lote)


def reconciliar(config: Config) -> dict[str, Any]:
    lote = interpretar_lote(config)
    cuentas_liquidaciones = {
        r["clave"]
        for r in lote.registros
        if str(r["tipo"]).startswith("liquidacion_") and r["clave"]
    }
    propietarios = {
        r["clave"] for r in lote.registros if r["tipo"] == "propietario"
    }
    cuentas_sin_propietario = sorted(cuentas_liquidaciones - propietarios)
    if cuentas_sin_propietario:
        lote.advertencias.append(
            {
                "codigo": "liquidaciones_sin_propietario_en_lote",
                "mensaje": "Hay cuentas de liquidacion sin propietario en PROPIETAR.TXT.",
                "datos": {"cuentas": cuentas_sin_propietario[:50]},
            }
        )
    return _resultado("reconciliado", None, lote)


def _persistir_lote(config: Config, lote: LoteInterpretado) -> int:
    from psycopg.types.json import Json

    inicio = datetime.now(timezone.utc)
    lote_hash = _hash_lote(lote)
    with conectar(config) as conn:
        with conn.transaction():
            with conn.cursor() as cur:
                cur.execute(
                    """
                    SELECT web_id
                    FROM web_importaciones
                    WHERE web_tipo = %s AND web_lote_hash = %s
                    LIMIT 1
                    """,
                    ("kng_gei", lote_hash),
                )
                existente = cur.fetchone()
                if existente is not None:
                    return int(existente[0])

                cur.execute(
                    """
                    INSERT INTO web_importaciones (
                        web_tipo, web_lote_hash, web_periodo_detectado, web_estado,
                        web_inicio_en, web_ejecutor, web_cantidad_archivos,
                        web_registros_leidos, web_registros_validos,
                        web_advertencias, web_errores, web_mensaje,
                        created_at, updated_at
                    )
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,now(),now())
                    RETURNING web_id
                    """,
                    (
                        "kng_gei",
                        lote_hash,
                        _periodo_principal(lote),
                        "FINALIZADO_CON_ADVERTENCIAS"
                        if lote.advertencias
                        else "FINALIZADO",
                        inicio,
                        os.environ.get("USER") or "python",
                        len(lote.resumenes),
                        lote.registros_leidos,
                        lote.registros_validos,
                        len(lote.advertencias),
                        len(lote.errores),
                        "Importacion staging web_ sin modificar tablas heredadas",
                    ),
                )
                importacion_id = int(cur.fetchone()[0])

                for resumen in lote.resumenes.values():
                    cur.execute(
                        """
                        SELECT web_id
                        FROM web_importaciones_archivos
                        WHERE web_tipo = %s AND web_hash_sha256 = %s
                        LIMIT 1
                        """,
                        (_tipo_archivo(resumen.nombre), resumen.sha256),
                    )
                    if cur.fetchone() is not None:
                        continue

                    cur.execute(
                        """
                        INSERT INTO web_importaciones_archivos (
                            web_importacion_id, web_nombre, web_tipo,
                            web_hash_sha256, web_tamano, web_fecha_archivo,
                            web_lineas, web_procesadas, web_rechazadas,
                            web_estado, web_periodo_detectado, created_at, updated_at
                        )
                        VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,now(),now())
                        """,
                        (
                            importacion_id,
                            resumen.nombre,
                            _tipo_archivo(resumen.nombre),
                            resumen.sha256,
                            resumen.ruta.stat().st_size,
                            datetime.fromtimestamp(
                                resumen.ruta.stat().st_mtime,
                                timezone.utc,
                            ),
                            resumen.registros,
                            resumen.validos,
                            resumen.errores,
                            "procesado" if resumen.errores == 0 else "con_errores",
                            None,
                        ),
                    )

                for evento in lote.advertencias:
                    cur.execute(
                        """
                        INSERT INTO web_importaciones_eventos (
                            web_importacion_id, web_tipo, web_severidad,
                            web_codigo, web_mensaje, web_datos, created_at, updated_at
                        )
                        VALUES (%s,%s,%s,%s,%s,%s,now(),now())
                        """,
                        (
                            importacion_id,
                            "conciliacion",
                            "advertencia",
                            evento["codigo"],
                            evento["mensaje"],
                            Json(evento.get("datos", {})),
                        ),
                    )

                for error in lote.errores:
                    cur.execute(
                        """
                        INSERT INTO web_importaciones_eventos (
                            web_importacion_id, web_archivo, web_linea,
                            web_tipo, web_severidad, web_codigo, web_mensaje,
                            web_contenido, created_at, updated_at
                        )
                        VALUES (%s,%s,%s,%s,%s,%s,%s,%s,now(),now())
                        """,
                        (
                            importacion_id,
                            error.get("archivo"),
                            error.get("linea"),
                            "parseo",
                            "error",
                            "linea_invalida",
                            error.get("mensaje"),
                            error.get("valor"),
                        ),
                    )

                for registro in lote.registros:
                    cur.execute(
                        """
                        INSERT INTO web_importaciones_registros (
                            web_importacion_id, web_archivo, web_linea,
                            web_tipo, web_clave, web_periodo, web_payload,
                            created_at, updated_at
                        )
                        VALUES (%s,%s,%s,%s,%s,%s,%s,now(),now())
                        """,
                        (
                            importacion_id,
                            registro["archivo"],
                            registro["linea"],
                            registro["tipo"],
                            registro["clave"],
                            registro["periodo"],
                            Json(registro["payload"], dumps=_json_dumps),
                        ),
                    )

                cur.execute(
                    """
                    UPDATE web_importaciones
                    SET web_finalizacion_en = now(), updated_at = now()
                    WHERE web_id = %s
                    """,
                    (importacion_id,),
                )

                return importacion_id


def _json_dumps(value: Any) -> str:
    return json.dumps(value, default=str, ensure_ascii=False)


def _hash_lote(lote: LoteInterpretado) -> str:
    partes = [
        f"{nombre}:{resumen.sha256}"
        for nombre, resumen in sorted(lote.resumenes.items())
    ]
    return sha256("|".join(partes).encode("utf-8")).hexdigest()


def _periodo_principal(lote: LoteInterpretado) -> str | None:
    periodos_liquidaciones = [
        str(r["periodo"])
        for r in lote.registros
        if str(r.get("tipo", "")).startswith("liquidacion_")
        and r.get("periodo")
        and str(r["periodo"]).strip("0")
    ]
    if periodos_liquidaciones:
        return max(
            set(periodos_liquidaciones),
            key=periodos_liquidaciones.count,
        )

    periodos = [
        str(r["periodo"])
        for r in lote.registros
        if r.get("periodo") and str(r["periodo"]).strip("0")
    ]
    return max(set(periodos), key=periodos.count) if periodos else None


def _tipo_archivo(nombre: str) -> str:
    if nombre in COBOL_ESPERADOS:
        return "cobol"
    return "liquidacion"


def _resultado(estado: str, importacion_id: int | None, lote: LoteInterpretado) -> dict[str, Any]:
    return {
        "estado": estado,
        "importacion_id": importacion_id,
        "periodo": _periodo_principal(lote),
        "archivos": len(lote.resumenes),
        "registros_leidos": lote.registros_leidos,
        "registros_validos": lote.registros_validos,
        "registros_interpretados": len(lote.registros),
        "advertencias": len(lote.advertencias),
        "errores": len(lote.errores),
        "resumen_archivos": {
            nombre: resumen.to_dict()
            for nombre, resumen in lote.resumenes.items()
        },
        "advertencias_detalle": lote.advertencias,
        "errores_detalle": lote.errores[:100],
        "escritura_postgresql": importacion_id is not None,
    }
