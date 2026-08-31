from __future__ import annotations

import re
from datetime import date
from pathlib import Path

from gei_importador.cobol.base import detectar_encoding, leer_lineas, sha256_archivo
from gei_importador.models import LiquidacionTxt
from gei_importador.resultado import ResumenArchivo


CUENTA_RE = re.compile(r"\b([12]202)/(\d{5})/(\d{2})\b")
FECHA_RE = re.compile(r"\b(\d{2})/(\d{2})/(\d{4})\b")
COMPROBANTE_RE = re.compile(r"\b(\d{5,8})\b")
PERIODO_RE = re.compile(
    r"\b(ENERO|FEBRERO|MARZO|ABRIL|MAYO|JUNIO|JULIO|AGOSTO|SEPTIEMBRE|OCTUBRE|NOVIEMBRE|DICIEMBRE)\s+(\d{4})\b",
    re.IGNORECASE,
)


def _fecha(match: re.Match[str] | None) -> date | None:
    if match is None:
        return None
    dia, mes, anio = map(int, match.groups())
    return date(anio, mes, dia)


def _cuenta(match: re.Match[str] | None) -> str:
    if match is None:
        return ""
    return "".join(match.groups())


def _periodo(linea: str) -> str:
    match = PERIODO_RE.search(linea)
    if match is None:
        return ""
    return f"{match.group(1).capitalize()}/{match.group(2)}"


def _tipo_y_sede(nombre: str) -> tuple[str, str]:
    bajo = nombre.lower()
    sede = "santa_fe" if ".sf" in bajo or "dailoc.sf" in bajo else "santo_tome"
    if bajo.startswith("liquidb"):
        return "liquidb", sede
    if bajo.startswith("dailoc"):
        return "dailoc", sede
    return "liquida", sede


def validar_archivo(path: Path) -> tuple[ResumenArchivo, list[LiquidacionTxt]]:
    encoding = detectar_encoding(path)
    nombre = path.name
    tipo, sede = _tipo_y_sede(nombre)
    resumen = ResumenArchivo(
        nombre=nombre,
        ruta=path,
        encoding=encoding,
        sha256=sha256_archivo(path),
    )
    registros: list[LiquidacionTxt] = []

    for numero_linea, linea in leer_lineas(path, encoding):
        if not linea.strip():
            continue

        resumen.registros += 1
        cuenta_match = CUENTA_RE.search(linea)
        if cuenta_match is None:
            continue

        fecha = _fecha(FECHA_RE.search(linea))
        comprobantes = COMPROBANTE_RE.findall(linea)
        numero_de_comprobante = comprobantes[-1] if comprobantes else ""
        registros.append(
            LiquidacionTxt(
                archivo=nombre,
                linea=numero_linea,
                sede=sede,
                tipo=tipo,
                fecha=fecha,
                cuenta=_cuenta(cuenta_match),
                periodo=_periodo(linea),
                numero_de_comprobante=numero_de_comprobante,
                raw=linea,
            )
        )
        resumen.validos += 1

    return resumen, registros
