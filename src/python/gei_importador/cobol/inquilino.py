from __future__ import annotations

from datetime import date, timedelta
from pathlib import Path

from gei_importador.cobol.base import (
    detectar_encoding,
    leer_registros_fijos,
    parsear_fecha_ddmmaaaa,
    registrar_error,
    sha256_archivo,
    val_foxpro,
)
from gei_importador.models import InquilinoCobol, limpiar_campo
from gei_importador.resultado import ResumenArchivo


NOMBRE_ARCHIVO = "INQUILINO.TXT"
LONGITUD_REGISTRO_KNG = 561
LONGITUD_CUERPO = 560


def _fecha_o_nula(valor: str) -> date | None:
    try:
        return parsear_fecha_ddmmaaaa(valor)
    except ValueError:
        return None


def parsear_linea(linea: str, hoy: date | None = None) -> InquilinoCobol:
    hoy = hoy or date.today()
    fecha_baja = _fecha_o_nula(linea[145:153])
    omitido_por_baja_antigua = (
        fecha_baja is not None and fecha_baja + timedelta(days=120) < hoy
    )
    documento = linea[348:357]
    if val_foxpro(documento) == 0:
        documento = ""

    return InquilinoCobol(
        cuenta=str(val_foxpro(linea[0:11])),
        cuenta_propietario=str(val_foxpro(linea[11:22])),
        nombre=limpiar_campo(linea[22:57]),
        domicilio_inmueble=limpiar_campo(linea[57:92]),
        fecha_contrato=_fecha_o_nula(linea[92:100]),
        fecha_vencimiento=_fecha_o_nula(linea[100:108]),
        fecha_baja=fecha_baja,
        telefono_particular=limpiar_campo(linea[156:170]),
        telefono_laboral=limpiar_campo(linea[170:184]),
        destino=val_foxpro(linea[289:292]),
        fecha_inicio=_fecha_o_nula(linea[325:333]),
        tipo_documento=val_foxpro(linea[347:348]),
        documento=limpiar_campo(documento),
        domicilio_legal=limpiar_campo(linea[357:392]),
        codigo_postal=val_foxpro(linea[392:396]),
        localidad=limpiar_campo(linea[396:421]),
        provincia=limpiar_campo(linea[422:432]),
        personeria_fiscal=val_foxpro(linea[526:527]),
        identificacion_fiscal=val_foxpro(linea[527:539]),
        omitido_por_baja_antigua=omitido_por_baja_antigua,
        raw=linea,
    )


def validar_archivo(path: Path) -> tuple[ResumenArchivo, list[InquilinoCobol]]:
    encoding = detectar_encoding(path)
    resumen = ResumenArchivo(
        nombre=NOMBRE_ARCHIVO,
        ruta=path,
        encoding=encoding,
        sha256=sha256_archivo(path),
    )
    registros: list[InquilinoCobol] = []
    cuentas_vistas: set[str] = set()

    cantidad_bytes = path.stat().st_size
    if cantidad_bytes % LONGITUD_REGISTRO_KNG != 0:
        registrar_error(
            resumen,
            NOMBRE_ARCHIVO,
            0,
            (
                f"Tamano invalido: {cantidad_bytes} bytes; no es multiplo de "
                f"{LONGITUD_REGISTRO_KNG}, longitud fija declarada por KNG"
            ),
            str(cantidad_bytes),
        )

    for numero_linea, registro_fijo in leer_registros_fijos(
        path,
        encoding,
        LONGITUD_REGISTRO_KNG,
    ):
        resumen.registros += 1

        if len(registro_fijo) != LONGITUD_REGISTRO_KNG:
            registrar_error(
                resumen,
                NOMBRE_ARCHIVO,
                numero_linea,
                (
                    f"Longitud invalida: {len(registro_fijo)} caracteres; "
                    f"se esperaban {LONGITUD_REGISTRO_KNG}"
                ),
                registro_fijo[:120],
            )
            continue

        if not registro_fijo.endswith("\n"):
            registrar_error(
                resumen,
                NOMBRE_ARCHIVO,
                numero_linea,
                "Registro fijo sin salto de linea final dentro de los 561 caracteres",
                registro_fijo[-20:],
            )
            continue

        linea = registro_fijo[:-1]
        if len(linea) != LONGITUD_CUERPO:
            registrar_error(
                resumen,
                NOMBRE_ARCHIVO,
                numero_linea,
                (
                    f"Cuerpo de registro invalido: {len(linea)} caracteres; "
                    f"se esperaban {LONGITUD_CUERPO}"
                ),
                linea[:120],
            )
            continue

        try:
            registro = parsear_linea(linea)
        except ValueError as exc:
            registrar_error(resumen, NOMBRE_ARCHIVO, numero_linea, str(exc), linea[:120])
            continue
        for campo, valor in (
            ("fecha_contrato", linea[92:100]),
            ("fecha_vencimiento", linea[100:108]),
            ("fecha_baja", linea[145:153]),
            ("fecha_inicio", linea[325:333]),
        ):
            if valor.strip() and val_foxpro(valor) != 0 and getattr(registro, campo) is None:
                resumen.metadata["fechas_invalidas_conservadas"] = (
                    int(resumen.metadata.get("fechas_invalidas_conservadas", 0)) + 1
                )

        if int(registro.cuenta) <= 0:
            registrar_error(
                resumen,
                NOMBRE_ARCHIVO,
                numero_linea,
                "Campo id_inq invalido segun VAL(SUBSTR(..., car, 11))",
                linea[0:11],
            )
            continue

        if int(registro.cuenta_propietario) <= 0:
            registrar_error(
                resumen,
                NOMBRE_ARCHIVO,
                numero_linea,
                "Campo id_prop invalido segun VAL(SUBSTR(..., car+11, 11))",
                linea[11:22],
            )
            continue

        if registro.cuenta in cuentas_vistas:
            registrar_error(
                resumen,
                NOMBRE_ARCHIVO,
                numero_linea,
                "Cuenta de inquilino duplicada en INQUILINO.TXT",
                registro.cuenta,
            )
            continue

        cuentas_vistas.add(registro.cuenta)

        registros.append(registro)
        resumen.validos += 1
        if registro.omitido_por_baja_antigua:
            resumen.metadata["omitidos_por_baja_antigua"] = (
                int(resumen.metadata.get("omitidos_por_baja_antigua", 0)) + 1
            )

    return resumen, registros
