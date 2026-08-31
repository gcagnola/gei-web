from __future__ import annotations

import csv
import json
from collections import Counter, defaultdict
from dataclasses import dataclass
from pathlib import Path
from typing import Any

ACCIONES = [
    "INSERTAR",
    "OMITIR_EXISTENTE",
    "ACTUALIZAR",
    "CONFLICTO",
    "ERROR_VALIDACION",
]

CAUSAS = {
    "NORMALIZACION_TEXTO",
    "FECHA_VACIA_VS_NULL",
    "NUMERO_CERO_IZQUIERDA",
    "DIFERENCIA_FISCAL",
    "DIFERENCIA_DOMICILIO",
    "DIFERENCIA_CLAVE_FUNCIONAL",
    "RELACION_HISTORICA_AUSENTE",
    "POSTGRESQL_TIENE_DATO_MANUAL",
    "FUENTE_COBOL_CAMBIO_REAL",
    "SIN_DETERMINAR",
}


@dataclass(frozen=True)
class ReportesAuditoriaPlan:
    resumen_entidad_json: Path
    resumen_entidad_csv: Path
    conflictos_resumen_json: Path
    conflictos_resumen_csv: Path
    conflictos_detalle_json: Path
    conflictos_detalle_csv: Path
    insertar_sospechosos_json: Path
    insertar_sospechosos_csv: Path


def auditar_plan_maestros(plan_path: Path, salida_dir: Path) -> ReportesAuditoriaPlan:
    rows = json.loads(plan_path.read_text(encoding="utf-8"))
    salida_dir.mkdir(parents=True, exist_ok=True)

    resumen_entidad = resumen_por_entidad(rows)
    conflictos_resumen = resumen_conflictos(rows)
    conflictos_detalle = detalle_conflictos(rows)
    insertar_sospechosos = detectar_insertar_sospechosos(rows)

    paths = ReportesAuditoriaPlan(
        resumen_entidad_json=salida_dir / "plan_maestros_resumen_entidad.json",
        resumen_entidad_csv=salida_dir / "plan_maestros_resumen_entidad.csv",
        conflictos_resumen_json=salida_dir / "plan_maestros_conflictos_resumen.json",
        conflictos_resumen_csv=salida_dir / "plan_maestros_conflictos_resumen.csv",
        conflictos_detalle_json=salida_dir / "plan_maestros_conflictos_detalle.json",
        conflictos_detalle_csv=salida_dir / "plan_maestros_conflictos_detalle.csv",
        insertar_sospechosos_json=salida_dir / "plan_maestros_insertar_sospechosos.json",
        insertar_sospechosos_csv=salida_dir / "plan_maestros_insertar_sospechosos.csv",
    )

    _write_json(paths.resumen_entidad_json, resumen_entidad)
    _write_csv(paths.resumen_entidad_csv, resumen_entidad, ["entidad", *ACCIONES])

    _write_json(paths.conflictos_resumen_json, conflictos_resumen)
    _write_csv(
        paths.conflictos_resumen_csv,
        conflictos_resumen,
        ["entidad", "cantidad_conflictos", "porcentaje_sobre_entidad", "campos"],
    )

    _write_json(paths.conflictos_detalle_json, conflictos_detalle)
    _write_csv(
        paths.conflictos_detalle_csv,
        conflictos_detalle,
        [
            "entidad",
            "clave_funcional",
            "tabla_destino",
            "accion",
            "causa_probable",
            "campos",
            "diferencias",
        ],
    )

    _write_json(paths.insertar_sospechosos_json, insertar_sospechosos)
    _write_csv(
        paths.insertar_sospechosos_csv,
        insertar_sospechosos,
        ["entidad", "clave_funcional", "tabla_destino", "causa_probable", "motivo"],
    )

    return paths


def resumen_por_entidad(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    entidades = sorted({row["entidad"] for row in rows})
    resumen = []
    for entidad in entidades:
        acciones = Counter(row["accion"] for row in rows if row["entidad"] == entidad)
        item = {"entidad": entidad}
        item.update({accion: acciones.get(accion, 0) for accion in ACCIONES})
        resumen.append(item)
    return resumen


def resumen_conflictos(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    total_por_entidad = Counter(row["entidad"] for row in rows)
    conflictos = [row for row in rows if row["accion"] == "CONFLICTO"]
    conflictos_por_entidad = Counter(row["entidad"] for row in conflictos)
    campos_por_entidad: dict[str, Counter[str]] = defaultdict(Counter)

    for row in conflictos:
        for diff in row.get("diferencias", []):
            campos_por_entidad[row["entidad"]][diff.get("campo", "")] += 1

    resumen = []
    for entidad, cantidad in sorted(conflictos_por_entidad.items()):
        total = total_por_entidad[entidad]
        campos = [
            {"campo": campo, "cantidad": campo_cantidad}
            for campo, campo_cantidad in campos_por_entidad[entidad].most_common()
        ]
        resumen.append(
            {
                "entidad": entidad,
                "cantidad_conflictos": cantidad,
                "porcentaje_sobre_entidad": round((cantidad / total) * 100, 2)
                if total
                else 0,
                "campos": campos,
            }
        )
    return resumen


def detalle_conflictos(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    detalle = []
    for row in rows:
        if row["accion"] != "CONFLICTO":
            continue
        causa = clasificar_causa(row)
        detalle.append(
            {
                "entidad": row["entidad"],
                "clave_funcional": row["clave_funcional"],
                "tabla_destino": row["tabla_destino"],
                "accion": row["accion"],
                "causa_probable": causa,
                "campos_fuente": row.get("campos_fuente"),
                "campos_postgresql": row.get("campos_postgresql"),
                "diferencias": row.get("diferencias", []),
                "campos": sorted(
                    {diff.get("campo", "") for diff in row.get("diferencias", [])}
                ),
                "regla_propuesta": regla_propuesta(causa),
            }
        )
    return detalle


def detectar_insertar_sospechosos(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    sospechosos = []
    acciones_por_entidad = {
        entidad: Counter(row["accion"] for row in rows if row["entidad"] == entidad)
        for entidad in {row["entidad"] for row in rows}
    }
    for row in rows:
        if row["accion"] != "INSERTAR":
            continue
        entidad = row["entidad"]
        acciones = acciones_por_entidad[entidad]
        motivo = None
        causa = None

        if entidad in {"inmueble_propietario", "contrato_inmueble"}:
            motivo = (
                "Insercion masiva de relacion: puede indicar que la clave extendida "
                "de staging es mas estricta que la regla historica de GeI."
            )
            causa = "RELACION_HISTORICA_AUSENTE"
        elif entidad == "contrato" and acciones.get("CONFLICTO", 0) > 0:
            motivo = (
                "Contrato nuevo propuesto en una entidad con muchos conflictos; "
                "revisar regla historica codigo_cliente + id_inq versus clave extendida."
            )
            causa = "DIFERENCIA_CLAVE_FUNCIONAL"
        elif entidad == "inmueble" and acciones.get("OMITIR_EXISTENTE", 0) > 0:
            motivo = (
                "Inmueble no encontrado pese a alta tasa de coincidencia por domicilio; "
                "puede ser normal o una diferencia de normalizacion de domicilio."
            )
            causa = "DIFERENCIA_DOMICILIO"

        if motivo:
            sospechosos.append(
                {
                    "entidad": entidad,
                    "clave_funcional": row["clave_funcional"],
                    "tabla_destino": row["tabla_destino"],
                    "causa_probable": causa,
                    "motivo": motivo,
                    "campos_fuente": row.get("campos_fuente"),
                }
            )
    return sospechosos


def clasificar_causa(row: dict[str, Any]) -> str:
    diferencias = row.get("diferencias", [])
    campos = {diff.get("campo", "") for diff in diferencias}

    if not diferencias:
        return "SIN_DETERMINAR"

    if all(_texto_normalizable(diff) for diff in diferencias):
        return "NORMALIZACION_TEXTO"

    if any(_fecha_vacia_vs_null(diff) for diff in diferencias):
        return "FECHA_VACIA_VS_NULL"

    if any(_numero_cero_izquierda(diff) for diff in diferencias):
        return "NUMERO_CERO_IZQUIERDA"

    if campos.intersection({"cuit", "docnro", "doctipo", "condicion_iva", "personeria"}):
        return "DIFERENCIA_FISCAL"

    if campos.intersection({"domicilio", "domicilio_calle", "localidad", "provincia", "cp"}):
        return "DIFERENCIA_DOMICILIO"

    if row["entidad"] in {"contrato", "contrato_inquilino", "contrato_inmueble"} and campos.intersection(
        {"fecha_contrato", "fecha_inicio", "fecha_fin", "numero_de_contrato", "plazo"}
    ):
        return "DIFERENCIA_CLAVE_FUNCIONAL"

    if campos.intersection({"id_prop", "id_inq"}):
        return "POSTGRESQL_TIENE_DATO_MANUAL"

    return "SIN_DETERMINAR"


def regla_propuesta(causa: str) -> str:
    return {
        "NORMALIZACION_TEXTO": "Comparar con trim, mayusculas y normalizacion de acentos antes de marcar conflicto.",
        "FECHA_VACIA_VS_NULL": "Tratar fecha vacia, null y 1900-01-01 como equivalentes solo si la regla Fox lo confirma.",
        "NUMERO_CERO_IZQUIERDA": "Normalizar codigos numericos quitando ceros a izquierda para comparacion.",
        "DIFERENCIA_FISCAL": "No actualizar automaticamente; requiere decision de negocio.",
        "DIFERENCIA_DOMICILIO": "Revisar normalizacion de domicilio y busqueda historica por domicilio_calle.",
        "DIFERENCIA_CLAVE_FUNCIONAL": "Revisar clave extendida contra regla historica GeI antes de insertar.",
        "RELACION_HISTORICA_AUSENTE": "Reconciliar relaciones por claves PostgreSQL existentes antes de insertar.",
        "POSTGRESQL_TIENE_DATO_MANUAL": "Preservar dato PostgreSQL salvo autorizacion explicita.",
        "FUENTE_COBOL_CAMBIO_REAL": "Tratar como cambio real de origen; no resolver automaticamente.",
        "SIN_DETERMINAR": "Auditar manualmente con fuente COBOL, DBF y PostgreSQL.",
    }.get(causa, "Auditar manualmente.")


def _texto_normalizable(diff: dict[str, Any]) -> bool:
    a = diff.get("valor_fuente")
    b = diff.get("valor_postgresql")
    if not isinstance(a, str) or not isinstance(b, str):
        return False
    return _norm_text(a) == _norm_text(b)


def _fecha_vacia_vs_null(diff: dict[str, Any]) -> bool:
    valores = {_blank_date(diff.get("valor_fuente")), _blank_date(diff.get("valor_postgresql"))}
    return valores == {"VACIA"}


def _numero_cero_izquierda(diff: dict[str, Any]) -> bool:
    a = str(diff.get("valor_fuente", "")).strip()
    b = str(diff.get("valor_postgresql", "")).strip()
    return a.isdigit() and b.isdigit() and int(a) == int(b)


def _blank_date(value: Any) -> str:
    if value in {None, "", "1900-01-01", "0000-00-00"}:
        return "VACIA"
    return str(value)


def _norm_text(value: str) -> str:
    return " ".join(value.strip().upper().split())


def _write_json(path: Path, data: Any) -> None:
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2, default=str), encoding="utf-8")


def _write_csv(path: Path, rows: list[dict[str, Any]], fieldnames: list[str]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames)
        writer.writeheader()
        for row in rows:
            writer.writerow(
                {
                    key: json.dumps(row.get(key), ensure_ascii=False, default=str)
                    if isinstance(row.get(key), (dict, list))
                    else row.get(key)
                    for key in fieldnames
                }
            )
