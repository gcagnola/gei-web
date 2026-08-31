from __future__ import annotations

from datetime import date
from decimal import Decimal, InvalidOperation
from pathlib import Path

from gei_importador.cobol.base import (
    detectar_encoding,
    leer_lineas,
    registrar_error,
    sha256_archivo,
    val_foxpro,
)
from gei_importador.models import MovimientoCuentaCobol, limpiar_campo
from gei_importador.resultado import ResumenArchivo


def _importe(valor: str) -> Decimal:
    texto = valor.strip()
    if texto == "":
        return Decimal("0")

    signo = Decimal("-1") if texto.startswith("-") or texto.endswith("-") else Decimal("1")
    texto = texto.strip("+-").replace(",", ".")
    if texto == "":
        return Decimal("0")

    try:
        return signo * (Decimal(texto) / Decimal("100"))
    except InvalidOperation:
        digitos = "".join(c for c in texto if c.isdigit())
        if digitos == "":
            return Decimal("0")
        return signo * (Decimal(digitos) / Decimal("100"))


def _fecha_aaaammdd(valor: str) -> date | None:
    texto = valor.strip()
    if texto == "" or set(texto) == {"0"}:
        return None
    if len(texto) != 8 or not texto.isdigit():
        return None
    anio = int(texto[0:4])
    if anio < 1900 or anio > 2100:
        return None
    try:
        return date(anio, int(texto[4:6]), int(texto[6:8]))
    except ValueError:
        return None


def parsear_linea(
    archivo: str,
    numero_linea: int,
    linea: str,
) -> MovimientoCuentaCobol:
    cuenta = str(val_foxpro(linea[:11]))
    fecha = _fecha_aaaammdd(linea[11:19])
    numero_movimiento = limpiar_campo(linea[19:27])

    if archivo.upper() == "INQCTACTE.TXT":
        vencimiento = _fecha_aaaammdd(linea[27:35])
        periodo = vencimiento.strftime("%Y%m") if vencimiento else ""
        debe = _importe(linea[35:47])
        haber = _importe(linea[47:59])
        saldo = _importe(linea[59:71])
        concepto = limpiar_campo(linea[71:112])
    else:
        periodo = linea[11:17] if linea[11:17].isdigit() else ""
        importe = _importe(linea[27:39])
        debe = importe if importe >= 0 else Decimal("0")
        haber = -importe if importe < 0 else Decimal("0")
        saldo = None
        concepto = limpiar_campo(linea[39:80])

    return MovimientoCuentaCobol(
        archivo=archivo,
        linea=numero_linea,
        cuenta=cuenta,
        fecha=fecha,
        numero_movimiento=numero_movimiento,
        periodo=periodo,
        concepto=concepto,
        debe=debe,
        haber=haber,
        saldo=saldo,
        raw=linea,
    )


def validar_archivo(
    path: Path,
    nombre_archivo: str,
) -> tuple[ResumenArchivo, list[MovimientoCuentaCobol]]:
    encoding = detectar_encoding(path)
    resumen = ResumenArchivo(
        nombre=nombre_archivo,
        ruta=path,
        encoding=encoding,
        sha256=sha256_archivo(path),
    )
    registros: list[MovimientoCuentaCobol] = []

    for numero_linea, linea in leer_lineas(path, encoding):
        if not linea:
            continue

        resumen.registros += 1

        try:
            registro = parsear_linea(nombre_archivo, numero_linea, linea)
        except ValueError as exc:
            registrar_error(resumen, nombre_archivo, numero_linea, str(exc), linea[:120])
            continue

        if int(registro.cuenta) <= 0:
            registrar_error(
                resumen,
                nombre_archivo,
                numero_linea,
                "Cuenta invalida en primeros 11 caracteres",
                linea[:11],
            )
            continue

        registros.append(registro)
        resumen.validos += 1

    return resumen, registros
