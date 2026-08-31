from __future__ import annotations

import csv
import json
from collections import Counter
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from gei_importador.cobol.auditoria_plan_maestros import clasificar_causa


@dataclass(frozen=True)
class ReportesPlanFox:
    plan_json: Path
    plan_csv: Path
    resumen_json: Path
    resumen_csv: Path


REGLAS_FOX: dict[str, dict[str, str]] = {
    "propietario_cliente": {
        "clave_fox": "Clientes.Cuit o Clientes.DocNro; no actualiza cliente existente.",
        "regla": "cliente_existente_omitido",
        "evidencia": (
            "gei_utf8.sc2:2630-2687 busca propietario por Cuit/DocNro e inserta "
            "solo si no existe; no hay UPDATE de Clientes en importar_contratos."
        ),
    },
    "inquilino_cliente": {
        "clave_fox": "Clientes.Cuit o Clientes.DocNro; no actualiza cliente existente.",
        "regla": "cliente_existente_omitido",
        "evidencia": (
            "gei_utf8.sc2:920-944 y 1030-1040 buscan inquilino por Cuit/DocNro "
            "e insertan solo si no existe; no hay UPDATE de Clientes."
        ),
    },
    "inmueble": {
        "clave_fox": "Inmuebles.domicilio_calle exacto luego de ALLTRIM y CHRTRAN.",
        "regla": "inmueble_existente_omitido",
        "evidencia": (
            "gei_utf8.sc2:1104-1109 consulta Inmuebles por domicilio_calle e "
            "inserta solo cuando RECCOUNT('Inmueble')=0; no actualiza inmuebles."
        ),
    },
    "inmueble_propietario": {
        "clave_fox": "codigo_inmueble + codigo_cliente + id_prop.",
        "regla": "relacion_exacta",
        "evidencia": (
            "gei_utf8.sc2:1259-1292 consulta inmuebles_propietarios por "
            "codigo_inmueble, codigo_cliente e id_prop; inserta solo si falta."
        ),
    },
    "contrato": {
        "clave_fox": "contratos_inquilinos.codigo_cliente + contratos_inquilinos.id_inq.",
        "regla": "contrato_por_cliente_id_inq",
        "evidencia": (
            "gei_utf8.sc2:1305-1318 consulta contratos_inquilinos por "
            "codigo_cliente e id_inq; si existe no actualiza contratos."
        ),
    },
    "contrato_inquilino": {
        "clave_fox": "codigo_contrato + codigo_cliente + id_inq.",
        "regla": "relacion_contrato_inquilino",
        "evidencia": (
            "gei_utf8.sc2:1305-1357 crea contratos_inquilinos solo al crear "
            "un contrato nuevo; no actualiza relaciones existentes."
        ),
    },
    "contrato_inmueble": {
        "clave_fox": "codigo_contrato + codigo_inmueble.",
        "regla": "relacion_contrato_inmueble",
        "evidencia": (
            "gei_utf8.sc2:1339-1347 crea contratos_inmuebles solo al crear "
            "un contrato nuevo; no actualiza relaciones existentes."
        ),
    },
}


def reclasificar_plan_maestros_fox(
    plan_path: Path,
    salida_dir: Path,
) -> ReportesPlanFox:
    rows = json.loads(plan_path.read_text(encoding="utf-8"))
    salida_dir.mkdir(parents=True, exist_ok=True)

    reclasificados = reclasificar_rows(rows)
    resumen = resumen_reclasificacion(reclasificados)

    paths = ReportesPlanFox(
        plan_json=salida_dir / "plan_maestros_fox.json",
        plan_csv=salida_dir / "plan_maestros_fox.csv",
        resumen_json=salida_dir / "plan_maestros_fox_resumen.json",
        resumen_csv=salida_dir / "plan_maestros_fox_resumen.csv",
    )
    _write_json(paths.plan_json, reclasificados)
    _write_csv(
        paths.plan_csv,
        reclasificados,
        [
            "entidad",
            "clave_funcional_actual",
            "clave_fox_usada",
            "accion_original",
            "accion_fox",
            "motivo_reclasificacion",
            "regla_fox_aplicada",
            "evidencia",
            "causa_original",
            "clasificacion_insertar",
            "diferencias",
        ],
    )
    _write_json(paths.resumen_json, resumen)
    _write_csv(
        paths.resumen_csv,
        resumen,
        [
            "entidad",
            "accion_original",
            "accion_fox",
            "causa_original",
            "regla_aplicada",
            "cantidad",
        ],
    )
    return paths


def reclasificar_rows(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    contrato_insertar = {
        row["clave_funcional"]
        for row in rows
        if row.get("entidad") == "contrato" and row.get("accion") == "INSERTAR"
    }
    resultado = []
    for row in rows:
        resultado.append(reclasificar_row_fox(row, contrato_insertar))
    return resultado


def reclasificar_row_fox(
    row: dict[str, Any],
    contrato_insertar: set[str] | None = None,
) -> dict[str, Any]:
    contrato_insertar = contrato_insertar or set()
    entidad = row["entidad"]
    regla = REGLAS_FOX.get(entidad, {})
    accion_original = row["accion"]
    causa = clasificar_causa(row) if accion_original == "CONFLICTO" else ""
    accion_fox = accion_original
    motivo = row.get("motivo", "")
    clasificacion_insertar = ""

    if (
        entidad in REGLAS_FOX
        and accion_original in {"CONFLICTO", "ACTUALIZAR"}
        and row.get("campos_postgresql")
    ):
        accion_fox = "OMITIR_EXISTENTE"
        motivo = _motivo_omitir_existente(entidad)
    elif accion_original == "INSERTAR":
        clasificacion_insertar = clasificar_insertar_fox(row, contrato_insertar)
        accion_fox = "INSERTAR"
        motivo = _motivo_insertar(entidad, clasificacion_insertar)

    return {
        "entidad": entidad,
        "clave_funcional_actual": row["clave_funcional"],
        "clave_fox_usada": regla.get("clave_fox", "SIN_EVIDENCIA"),
        "accion_original": accion_original,
        "accion_fox": accion_fox,
        "motivo_reclasificacion": motivo,
        "regla_fox_aplicada": regla.get("regla", "sin_regla_fox"),
        "evidencia": regla.get("evidencia", "Sin evidencia Fox directa."),
        "causa_original": causa,
        "clasificacion_insertar": clasificacion_insertar,
        "diferencias": row.get("diferencias", []),
        "campos_fuente": row.get("campos_fuente"),
        "campos_postgresql": row.get("campos_postgresql"),
    }


def clasificar_insertar_fox(
    row: dict[str, Any],
    contrato_insertar: set[str] | None = None,
) -> str:
    contrato_insertar = contrato_insertar or set()
    entidad = row["entidad"]

    if entidad in {"propietario_cliente", "inquilino_cliente", "inmueble", "contrato"}:
        return "INSERTAR_REAL"

    if entidad == "inmueble_propietario":
        return "RELACION_HISTORICA_AUSENTE"

    if entidad in {"contrato_inquilino", "contrato_inmueble"}:
        contrato_clave = str(row.get("campos_fuente", {}).get("contrato_clave", ""))
        if contrato_clave in contrato_insertar:
            return "INSERTAR_REAL"
        return "RELACION_HISTORICA_AUSENTE"

    return "SIN_DETERMINAR"


def resumen_reclasificacion(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    contador = Counter(
        (
            row["entidad"],
            row["accion_original"],
            row["accion_fox"],
            row.get("causa_original", ""),
            row["regla_fox_aplicada"],
        )
        for row in rows
    )
    return [
        {
            "entidad": entidad,
            "accion_original": accion_original,
            "accion_fox": accion_fox,
            "causa_original": causa_original,
            "regla_aplicada": regla,
            "cantidad": cantidad,
        }
        for (
            entidad,
            accion_original,
            accion_fox,
            causa_original,
            regla,
        ), cantidad in sorted(contador.items())
    ]


def _motivo_omitir_existente(entidad: str) -> str:
    if entidad in {"propietario_cliente", "inquilino_cliente"}:
        return (
            "Fox encontro el cliente por Cuit/DocNro y no ejecuta UPDATE sobre "
            "Clientes; las diferencias quedan ignoradas en esta rutina."
        )
    if entidad == "inmueble":
        return (
            "Fox encontro el inmueble por domicilio_calle y no actualiza pais, "
            "provincia, localidad ni CP en importar_contratos."
        )
    if entidad == "contrato":
        return (
            "Fox encontro contrato por contratos_inquilinos.codigo_cliente + id_inq; "
            "no actualiza fechas, plazo, importe ni numero_de_contrato."
        )
    return "Fox encontro la relacion historica y no la actualiza."


def _motivo_insertar(entidad: str, clasificacion: str) -> str:
    if clasificacion == "INSERTAR_REAL":
        return (
            "Bajo la clave Fox documentada no hay registro equivalente en el plan; "
            "seria insercion si se habilitara escritura real."
        )
    if clasificacion == "RELACION_HISTORICA_AUSENTE":
        return (
            "La relacion exacta usada por Fox no aparece en PostgreSQL; requiere "
            "revision antes de confirmar porque puede depender de claves previas."
        )
    return "Insercion no resuelta con evidencia suficiente."


def _write_json(path: Path, data: Any) -> None:
    path.write_text(
        json.dumps(data, ensure_ascii=False, indent=2, default=str),
        encoding="utf-8",
    )


def _write_csv(path: Path, rows: list[dict[str, Any]], fieldnames: list[str]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames)
        writer.writeheader()
        for row in rows:
            writer.writerow(
                {
                    field: _csv_value(row.get(field))
                    for field in fieldnames
                }
            )


def _csv_value(value: Any) -> Any:
    if isinstance(value, (dict, list)):
        return json.dumps(value, ensure_ascii=False, default=str)
    return value
