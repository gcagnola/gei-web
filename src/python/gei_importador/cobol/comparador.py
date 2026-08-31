from __future__ import annotations

import re
from collections import Counter
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from gei_importador.config import Config
from gei_importador.cobol.base import detectar_encoding, leer_lineas
from gei_importador.cobol import inquilino, propietar
from gei_importador.database import conectar
from gei_importador.models import InquilinoCobol, PropietarioCobol
from gei_importador.repositories.importaciones import RepositorioArchivos
from gei_importador.resultado import ResultadoImportacion


@dataclass
class IndicesPostgres:
    clientes_por_cuit: dict[str, list[int]] = field(default_factory=dict)
    clientes_por_docnro: dict[str, list[int]] = field(default_factory=dict)
    clientes_por_id_inq: dict[int, list[int]] = field(default_factory=dict)
    clientes_por_id_prop: dict[int, list[int]] = field(default_factory=dict)
    inmuebles_por_domicilio: dict[str, list[int]] = field(default_factory=dict)
    contratos_por_id_inq: dict[int, list[dict[str, int]]] = field(default_factory=dict)
    inmuebles_propietarios: set[tuple[int, int, int]] = field(default_factory=set)
    inmuebles_propietarios_por_inmueble_id_prop: dict[tuple[int, int], set[int]] = field(
        default_factory=dict
    )


@dataclass
class ResultadoComparacion:
    existentes_sin_cambios: int = 0
    existentes_con_diferencias: int = 0
    nuevos: int = 0
    ambiguos: int = 0
    errores: int = 0
    omitidos_por_baja_antigua: int = 0
    ambiguos_resueltos_por_id_inq: int = 0
    motivos_resumen: Counter[str] = field(default_factory=Counter)
    cruces_resumen: dict[str, Counter[str]] = field(default_factory=dict)
    clientes_validados: set[int] = field(default_factory=set)
    clientes_operativos: set[int] = field(default_factory=set)
    muestras: list[dict[str, Any]] = field(default_factory=list)

    def registrar(self, estado: str, detalle: dict[str, Any]) -> None:
        match estado:
            case "existente_sin_cambios":
                self.existentes_sin_cambios += 1
            case "existente_con_diferencias":
                self.existentes_con_diferencias += 1
            case "nuevo":
                self.nuevos += 1
            case "ambiguo":
                self.ambiguos += 1
            case "omitido_por_baja_antigua":
                self.omitidos_por_baja_antigua += 1
            case _:
                self.errores += 1

        if "motivo" in detalle:
            self.motivos_resumen[str(detalle["motivo"])] += 1
        for diferencia in detalle.get("diferencias", []):
            self.motivos_resumen[str(diferencia)] += 1

        cruces = detalle.get("cruces")
        if isinstance(cruces, dict):
            motivos = detalle.get("diferencias") or [detalle.get("motivo", estado)]
            for motivo in motivos:
                resumen = self.cruces_resumen.setdefault(str(motivo), Counter())
                for clave, valor in cruces.items():
                    if isinstance(valor, bool):
                        resumen[f"{clave}={'si' if valor else 'no'}"] += 1

        if estado not in {
            "existente_sin_cambios",
            "omitido_por_baja_antigua",
        } and len(self.muestras) < 50:
            self.muestras.append({"estado": estado, **detalle})

    def to_dict(self) -> dict[str, Any]:
        return {
            "existentes_sin_cambios": self.existentes_sin_cambios,
            "existentes_con_diferencias": self.existentes_con_diferencias,
            "nuevos": self.nuevos,
            "ambiguos": self.ambiguos,
            "errores": self.errores,
            "omitidos_por_baja_antigua": self.omitidos_por_baja_antigua,
            "ambiguos_resueltos_por_id_inq": self.ambiguos_resueltos_por_id_inq,
            "clientes_validados_count": len(self.clientes_validados),
            "clientes_validados": sorted(self.clientes_validados),
            "clientes_operativos_count": len(self.clientes_operativos),
            "clientes_operativos": sorted(self.clientes_operativos),
            "motivos_resumen": dict(self.motivos_resumen),
            "cruces_resumen": {
                motivo: dict(resumen)
                for motivo, resumen in self.cruces_resumen.items()
            },
            "muestras": self.muestras,
        }


@dataclass
class CrucesCobol:
    ctactepro_por_id_prop: Counter[int] = field(default_factory=Counter)
    inqctacte_por_id_inq: Counter[int] = field(default_factory=Counter)
    liquidaciones_por_id_prop: Counter[int] = field(default_factory=Counter)
    pliqloc_por_id_prop: Counter[int] = field(default_factory=Counter)

    def para_registro(self, id_inq: int, id_prop: int) -> dict[str, bool]:
        return {
            "id_inq_en_inqctacte": id_inq in self.inqctacte_por_id_inq,
            "id_prop_en_ctactepro": id_prop in self.ctactepro_por_id_prop,
            "id_prop_en_liquidaciones": id_prop in self.liquidaciones_por_id_prop,
            "id_prop_en_pliqloc": id_prop in self.pliqloc_por_id_prop,
        }


def comparar_cobol(
    config: Config,
    repositorio: RepositorioArchivos,
) -> ResultadoImportacion:
    resultado = ResultadoImportacion(
        repositorio_id=repositorio.repositorio_id,
        modo="comparar",
        repositorio_path=repositorio.path,
        escritura_postgresql=False,
    )

    resumen_propietar, propietarios = propietar.validar_archivo(
        repositorio.archivo_cobol(propietar.NOMBRE_ARCHIVO)
    )
    resumen_inquilino, inquilinos = inquilino.validar_archivo(
        repositorio.archivo_cobol(inquilino.NOMBRE_ARCHIVO)
    )
    resultado.archivos[propietar.NOMBRE_ARCHIVO] = resumen_propietar
    resultado.archivos[inquilino.NOMBRE_ARCHIVO] = resumen_inquilino

    propietarios_por_id = {int(p.cuenta): p for p in propietarios}
    cruces = _cargar_cruces_cobol(repositorio.path)

    with conectar(config) as conn:
        indices = _cargar_indices(conn)

    comparacion = ResultadoComparacion()
    comparacion.clientes_operativos.update(
        _clientes_con_evidencia_operativa(indices, cruces)
    )
    for registro in inquilinos:
        _comparar_inquilino(
            registro,
            propietarios_por_id,
            indices,
            cruces,
            comparacion,
        )

    resultado.extra["comparacion_postgresql"] = comparacion.to_dict()
    resultado.extra["mensaje"] = (
        "La operacion se ejecuto en modo comparacion. "
        "No se realizaron cambios en PostgreSQL."
    )

    return resultado


def _clientes_con_evidencia_operativa(
    indices: IndicesPostgres,
    cruces: CrucesCobol,
) -> set[int]:
    clientes: set[int] = set()

    for id_prop in (
        set(cruces.ctactepro_por_id_prop)
        | set(cruces.liquidaciones_por_id_prop)
        | set(cruces.pliqloc_por_id_prop)
    ):
        clientes.update(indices.clientes_por_id_prop.get(id_prop, []))

    for id_inq in cruces.inqctacte_por_id_inq:
        clientes.update(indices.clientes_por_id_inq.get(id_inq, []))

    return clientes


def _cargar_cruces_cobol(repositorio_path: Path) -> CrucesCobol:
    liquidaciones_base = repositorio_path.parent
    cruces = CrucesCobol()

    _contar_cuentas_por_primeros_11(
        repositorio_path / "CTACTEPRO.TXT",
        cruces.ctactepro_por_id_prop,
    )
    _contar_cuentas_por_primeros_11(
        repositorio_path / "INQCTACTE.TXT",
        cruces.inqctacte_por_id_inq,
    )
    _contar_cuentas_liquidaciones(
        liquidaciones_base / "periodos",
        cruces.liquidaciones_por_id_prop,
        ("liquida.*.txt", "liquidb.*.txt"),
    )
    _contar_cuentas_liquidaciones(
        liquidaciones_base / "periodos",
        cruces.pliqloc_por_id_prop,
        ("pliqloc.*.txt",),
    )

    return cruces


def _contar_cuentas_por_primeros_11(path: Path, destino: Counter[int]) -> None:
    if not path.is_file():
        return

    encoding = detectar_encoding(path)
    for _numero_linea, linea in leer_lineas(path, encoding):
        cuenta = linea[:11]
        if cuenta.isdigit():
            destino[int(cuenta)] += 1


CUENTA_LIQUIDACION_RE = re.compile(r"\b([12]202)/(\d{5})/(\d{2})\b")


def _contar_cuentas_liquidaciones(
    periodos_path: Path,
    destino: Counter[int],
    patrones: tuple[str, ...],
) -> None:
    if not periodos_path.is_dir():
        return

    for patron in patrones:
        for path in periodos_path.glob(f"*/{patron}"):
            encoding = detectar_encoding(path)
            texto = path.read_text(encoding=encoding, errors="replace")
            for coincidencia in CUENTA_LIQUIDACION_RE.finditer(texto):
                cuenta = int("".join(coincidencia.groups()))
                destino[cuenta] += 1


def _cargar_indices(conn) -> IndicesPostgres:
    indices = IndicesPostgres()

    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT codigo_cliente, trim(cuit), trim(docnro), id_inq, id_prop
            FROM clientes
            """
        )
        for codigo_cliente, cuit, docnro, id_inq, id_prop in cur.fetchall():
            codigo_cliente = int(codigo_cliente)
            if cuit:
                indices.clientes_por_cuit.setdefault(cuit, []).append(codigo_cliente)
            if docnro:
                indices.clientes_por_docnro.setdefault(docnro, []).append(codigo_cliente)
            if int(id_inq) != 0:
                indices.clientes_por_id_inq.setdefault(int(id_inq), []).append(
                    codigo_cliente
                )
            if int(id_prop) != 0:
                indices.clientes_por_id_prop.setdefault(int(id_prop), []).append(
                    codigo_cliente
                )

        cur.execute("SELECT codigo_inmueble, trim(domicilio_calle) FROM inmuebles")
        for codigo_inmueble, domicilio in cur.fetchall():
            if domicilio:
                indices.inmuebles_por_domicilio.setdefault(domicilio, []).append(
                    int(codigo_inmueble)
                )

        cur.execute(
            """
            SELECT codigo_contrato, codigo_cliente, id_inq
            FROM contratos_inquilinos
            WHERE id_inq <> 0
            """
        )
        for codigo_contrato, codigo_cliente, id_inq in cur.fetchall():
            indices.contratos_por_id_inq.setdefault(int(id_inq), []).append(
                {
                    "codigo_contrato": int(codigo_contrato),
                    "codigo_cliente": int(codigo_cliente),
                }
            )

        cur.execute(
            """
            SELECT codigo_inmueble, codigo_cliente, id_prop
            FROM inmuebles_propietarios
            WHERE id_prop <> 0
            """
        )
        for codigo_inmueble, codigo_cliente, id_prop in cur.fetchall():
            codigo_inmueble = int(codigo_inmueble)
            codigo_cliente = int(codigo_cliente)
            id_prop = int(id_prop)
            indices.inmuebles_propietarios.add(
                (codigo_inmueble, codigo_cliente, id_prop)
            )
            indices.inmuebles_propietarios_por_inmueble_id_prop.setdefault(
                (codigo_inmueble, id_prop),
                set(),
            ).add(codigo_cliente)

    for valores in (
        indices.clientes_por_cuit,
        indices.clientes_por_docnro,
        indices.clientes_por_id_inq,
        indices.clientes_por_id_prop,
        indices.inmuebles_por_domicilio,
    ):
        for coincidencias in valores.values():
            coincidencias.sort()

    for coincidencias in indices.contratos_por_id_inq.values():
        coincidencias.sort(
            key=lambda item: (item["codigo_cliente"], item["codigo_contrato"])
        )

    for coincidencias in indices.inmuebles_propietarios_por_inmueble_id_prop.values():
        if not isinstance(coincidencias, set):
            continue
        # Se deja como set para consultas O(1); el orden solo importa al serializar
        # muestras puntuales, que hoy no incluyen esta estructura completa.
        pass

    return indices


def _preferir_unico_por_id(
    candidatos: list[int] | None,
    indice_por_id: dict[int, list[int]],
    identificador: int,
) -> tuple[list[int] | None, bool]:
    if candidatos is None or len(candidatos) <= 1:
        return candidatos, False

    por_identificador = indice_por_id.get(identificador, [])
    preferidos = [codigo for codigo in candidatos if codigo in por_identificador]

    if len(preferidos) == 1:
        return preferidos, True

    return candidatos, False


def _comparar_inquilino(
    registro: InquilinoCobol,
    propietarios_por_id: dict[int, PropietarioCobol],
    indices: IndicesPostgres,
    cruces_cobol: CrucesCobol,
    comparacion: ResultadoComparacion,
) -> None:
    id_inq = int(registro.cuenta)
    id_prop = int(registro.cuenta_propietario)
    cruces = cruces_cobol.para_registro(id_inq, id_prop)

    if registro.omitido_por_baja_antigua:
        comparacion.registrar(
            "omitido_por_baja_antigua",
            {"id_inq": id_inq, "id_prop": id_prop, "nombre": registro.nombre},
        )
        return

    clientes, resuelto_por_id_inq = _buscar_clientes_inquilino(registro, indices)
    if resuelto_por_id_inq:
        comparacion.ambiguos_resueltos_por_id_inq += 1

    if clientes is None:
        comparacion.registrar(
            "ambiguo",
            {
                "id_inq": id_inq,
                "id_prop": id_prop,
                "nombre": registro.nombre,
                "motivo": "Sin CUIT ni documento para reproducir busqueda GeI",
                "cruces": cruces,
            },
        )
        return

    if len(clientes) > 1:
        comparacion.registrar(
            "ambiguo",
            {
                "id_inq": id_inq,
                "id_prop": id_prop,
                "nombre": registro.nombre,
                "motivo": "Mas de un cliente coincide por CUIT/docnro",
                "clientes": clientes,
                "cruces": cruces,
            },
        )
        return

    if not clientes:
        comparacion.registrar(
            "nuevo",
            {"id_inq": id_inq, "id_prop": id_prop, "nombre": registro.nombre},
        )
        return

    codigo_cliente = clientes[0]
    codigo_cliente_propietario: int | None = None
    diferencias: list[str] = []

    inmuebles = indices.inmuebles_por_domicilio.get(registro.domicilio_inmueble, [])
    if len(inmuebles) == 0:
        diferencias.append("No existe inmueble por domicilio_calle")
    elif len(inmuebles) > 1:
        comparacion.registrar(
            "ambiguo",
            {
                "id_inq": id_inq,
                "id_prop": id_prop,
                "nombre": registro.nombre,
                "motivo": "Mas de un inmueble coincide por domicilio_calle",
                "inmuebles": inmuebles,
                "cruces": cruces,
            },
        )
        return

    contratos = [
        c
        for c in indices.contratos_por_id_inq.get(id_inq, [])
        if c["codigo_cliente"] == codigo_cliente
    ]
    if not contratos:
        diferencias.append("No existe contratos_inquilinos por codigo_cliente + id_inq")

    propietario = propietarios_por_id.get(id_prop)
    if propietario is None:
        diferencias.append("No existe propietario KNG referenciado por id_prop")
    elif propietario.identificacion_fiscal:
        clientes_propietario, _resuelto_por_id_prop = _buscar_clientes_propietario(
            propietario,
            indices,
        )
        if len(clientes_propietario) == 0:
            diferencias.append("No existe cliente propietario por CUIT")
        elif len(clientes_propietario) > 1:
            comparacion.registrar(
                "ambiguo",
                {
                    "id_inq": id_inq,
                    "id_prop": id_prop,
                    "nombre": registro.nombre,
                    "motivo": "Mas de un propietario coincide por CUIT",
                    "clientes": clientes_propietario,
                    "cruces": cruces,
                },
            )
            return
        elif len(inmuebles) == 1:
            codigo_cliente_propietario = clientes_propietario[0]
            clave = (inmuebles[0], clientes_propietario[0], id_prop)
            if clave not in indices.inmuebles_propietarios:
                clientes_relacionados = (
                    indices.inmuebles_propietarios_por_inmueble_id_prop.get(
                        (inmuebles[0], id_prop),
                        set(),
                    )
                )
                if clientes_relacionados:
                    diferencias.append(
                        "Existe inmuebles_propietarios para inmueble + id_prop con otro codigo_cliente"
                    )
                else:
                    diferencias.append(
                        "No existe inmuebles_propietarios por inmueble + propietario + id_prop"
                    )

    if diferencias:
        comparacion.registrar(
            "existente_con_diferencias",
            {
                "id_inq": id_inq,
                "id_prop": id_prop,
                "codigo_cliente": codigo_cliente,
                "nombre": registro.nombre,
                "diferencias": diferencias,
                "cruces": cruces,
            },
        )
        return

    comparacion.registrar(
        "existente_sin_cambios",
        {
            "id_inq": id_inq,
            "id_prop": id_prop,
            "codigo_cliente": codigo_cliente,
            "codigo_cliente_propietario": codigo_cliente_propietario,
        },
    )
    comparacion.clientes_validados.add(codigo_cliente)
    if codigo_cliente_propietario is not None:
        comparacion.clientes_validados.add(codigo_cliente_propietario)


def _buscar_clientes_inquilino(
    registro: InquilinoCobol,
    indices: IndicesPostgres,
) -> tuple[list[int] | None, bool]:
    if registro.identificacion_fiscal:
        candidatos = indices.clientes_por_cuit.get(
            _formatear_cuit(registro.identificacion_fiscal),
            [],
        )
        return _preferir_unico_por_id(
            candidatos,
            indices.clientes_por_id_inq,
            int(registro.cuenta),
        )

    documento = registro.documento.strip()
    if len(documento) > 8:
        documento = documento[-8:]
    if documento == "":
        return None, False

    candidatos = indices.clientes_por_docnro.get(documento, [])
    return _preferir_unico_por_id(
        candidatos,
        indices.clientes_por_id_inq,
        int(registro.cuenta),
    )


def _buscar_clientes_propietario(
    registro: PropietarioCobol,
    indices: IndicesPostgres,
) -> tuple[list[int], bool]:
    candidatos = indices.clientes_por_cuit.get(
        _formatear_cuit(registro.identificacion_fiscal),
        [],
    )
    return _preferir_unico_por_id(
        candidatos,
        indices.clientes_por_id_prop,
        int(registro.cuenta),
    )


def _formatear_cuit(valor: int) -> str:
    digitos = f"{valor:011d}"
    return f"{digitos[0:2]}-{digitos[2:10]}-{digitos[10:11]}"
