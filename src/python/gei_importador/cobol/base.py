from __future__ import annotations

import hashlib
from datetime import date
from pathlib import Path
from typing import Iterable

from gei_importador.resultado import ErrorRegistro, ResumenArchivo


ENCODINGS_CANDIDATOS = ("cp1252", "utf-8", "latin-1")


def detectar_encoding(path: Path) -> str:
    data = path.read_bytes()

    for encoding in ENCODINGS_CANDIDATOS:
        try:
            data.decode(encoding)
        except UnicodeDecodeError:
            continue
        return encoding

    return "cp1252"


def sha256_archivo(path: Path) -> str:
    digest = hashlib.sha256()

    with path.open("rb") as archivo:
        for bloque in iter(lambda: archivo.read(1024 * 1024), b""):
            digest.update(bloque)

    return digest.hexdigest()


def leer_lineas(path: Path, encoding: str) -> Iterable[tuple[int, str]]:
    with path.open("r", encoding=encoding, errors="strict", newline="") as archivo:
        for numero, linea in enumerate(archivo, 1):
            yield numero, linea.rstrip("\r\n")


def leer_registros_fijos(
    path: Path,
    encoding: str,
    longitud_registro: int,
) -> Iterable[tuple[int, str]]:
    with path.open("r", encoding=encoding, errors="strict", newline="") as archivo:
        numero = 1
        while True:
            registro = archivo.read(longitud_registro)
            if registro == "":
                break

            yield numero, registro
            numero += 1


def validar_longitud(
    resumen: ResumenArchivo,
    archivo: str,
    numero_linea: int,
    linea: str,
    longitud_esperada: int,
) -> bool:
    if len(linea) == longitud_esperada:
        return True

    resumen.errores += 1
    resumen.errores_detalle.append(
        ErrorRegistro(
            archivo=archivo,
            linea=numero_linea,
            mensaje=(
                f"Longitud invalida: {len(linea)} caracteres; "
                f"se esperaban {longitud_esperada}"
            ),
            valor=linea[:120],
        )
    )
    return False


def validar_cuenta(
    resumen: ResumenArchivo,
    archivo: str,
    numero_linea: int,
    cuenta: str,
    campo: str = "cuenta",
) -> bool:
    if cuenta.isdigit():
        return True

    resumen.errores += 1
    resumen.errores_detalle.append(
        ErrorRegistro(
            archivo=archivo,
            linea=numero_linea,
            mensaje=f"Campo {campo} no numerico",
            valor=cuenta,
        )
    )
    return False


def registrar_error(
    resumen: ResumenArchivo,
    archivo: str,
    numero_linea: int,
    mensaje: str,
    valor: str = "",
) -> None:
    resumen.errores += 1
    resumen.errores_detalle.append(
        ErrorRegistro(
            archivo=archivo,
            linea=numero_linea,
            mensaje=mensaje,
            valor=valor,
        )
    )


def val_foxpro(valor: str) -> int:
    valor = valor.strip()
    if valor == "":
        return 0

    signo = -1 if valor.startswith("-") else 1
    if valor[:1] in "+-":
        valor = valor[1:]

    digitos = []
    for caracter in valor:
        if caracter.isdigit():
            digitos.append(caracter)
            continue
        break

    if not digitos:
        return 0

    return signo * int("".join(digitos))


def parsear_fecha_ddmmaaaa(valor: str) -> date | None:
    if valor.strip() == "" or val_foxpro(valor) == 0:
        return None

    if len(valor) != 8 or not valor.isdigit():
        raise ValueError(f"Fecha invalida: {valor!r}")

    dia = int(valor[0:2])
    mes = int(valor[2:4])
    anio = int(valor[4:8])

    return date(anio, mes, dia)
