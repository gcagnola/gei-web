from __future__ import annotations

from pathlib import Path

from gei_importador.cobol.base import (
    detectar_encoding,
    leer_lineas,
    registrar_error,
    sha256_archivo,
    validar_longitud,
    val_foxpro,
)
from gei_importador.models import PropietarioCobol, limpiar_campo
from gei_importador.resultado import ResumenArchivo


NOMBRE_ARCHIVO = "PROPIETAR.TXT"
LONGITUD_REGISTRO = 200


def parsear_linea(linea: str) -> PropietarioCobol:
    return PropietarioCobol(
        cuenta=str(val_foxpro(linea[0:11])),
        nombre=limpiar_campo(linea[11:46]),
        domicilio=limpiar_campo(linea[46:76]),
        codigo_postal=limpiar_campo(linea[76:80]),
        localidad=limpiar_campo(linea[80:106]),
        provincia=limpiar_campo(linea[106:116]),
        telefono=limpiar_campo(linea[116:130]),
        fecha_ultima_liquidacion=val_foxpro(linea[159:163]),
        personeria_fiscal=val_foxpro(linea[184:185]),
        identificacion_fiscal=val_foxpro(linea[185:197]),
        raw=linea,
    )


def validar_archivo(path: Path) -> tuple[ResumenArchivo, list[PropietarioCobol]]:
    encoding = detectar_encoding(path)
    resumen = ResumenArchivo(
        nombre=NOMBRE_ARCHIVO,
        ruta=path,
        encoding=encoding,
        sha256=sha256_archivo(path),
    )
    registros: list[PropietarioCobol] = []
    cuentas_vistas: set[str] = set()

    for numero_linea, linea in leer_lineas(path, encoding):
        if not linea:
            continue

        resumen.registros += 1

        if not validar_longitud(
            resumen,
            NOMBRE_ARCHIVO,
            numero_linea,
            linea,
            LONGITUD_REGISTRO,
        ):
            continue

        try:
            registro = parsear_linea(linea)
        except ValueError as exc:
            registrar_error(resumen, NOMBRE_ARCHIVO, numero_linea, str(exc), linea[:120])
            continue

        if int(registro.cuenta) <= 0:
            registrar_error(
                resumen,
                NOMBRE_ARCHIVO,
                numero_linea,
                "Campo cuenta invalido segun VAL(LEFT(linea, 11))",
                linea[0:11],
            )
            continue

        if registro.cuenta in cuentas_vistas:
            registrar_error(
                resumen,
                NOMBRE_ARCHIVO,
                numero_linea,
                "Cuenta de propietario duplicada en PROPIETAR.TXT",
                registro.cuenta,
            )
            continue

        cuentas_vistas.add(registro.cuenta)

        registros.append(registro)
        resumen.validos += 1

    return resumen, registros
