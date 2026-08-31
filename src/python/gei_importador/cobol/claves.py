from __future__ import annotations

from dataclasses import dataclass
from datetime import date

from gei_importador.models import InquilinoCobol, PropietarioCobol


@dataclass(frozen=True)
class ClavesPropietario:
    propietario_cliente: str


@dataclass(frozen=True)
class ClavesInquilino:
    inquilino_cliente: str
    inmueble: str
    contrato: str
    contrato_inquilino: str
    contrato_inmueble: str
    inmueble_propietario: str


def claves_propietario(registro: PropietarioCobol) -> ClavesPropietario:
    return ClavesPropietario(
        propietario_cliente=f"propietario_cliente:{registro.cuenta}",
    )


def claves_inquilino(registro: InquilinoCobol) -> ClavesInquilino:
    clave_inmueble = "|".join(
        [
            "inmueble",
            registro.cuenta_propietario,
            _normalizar_texto(registro.domicilio_inmueble),
            str(registro.codigo_postal),
            _normalizar_texto(registro.localidad),
        ]
    )
    clave_contrato = "|".join(
        [
            "contrato",
            registro.cuenta,
            registro.cuenta_propietario,
            _normalizar_texto(registro.domicilio_inmueble),
            _fecha(registro.fecha_contrato),
            _fecha(registro.fecha_inicio),
            _fecha(registro.fecha_vencimiento),
        ]
    )

    return ClavesInquilino(
        inquilino_cliente=f"inquilino_cliente:{registro.cuenta}",
        inmueble=clave_inmueble,
        contrato=clave_contrato,
        contrato_inquilino=f"contrato_inquilino:{clave_contrato}|{registro.cuenta}",
        contrato_inmueble=f"contrato_inmueble:{clave_contrato}|{clave_inmueble}",
        inmueble_propietario=(
            f"inmueble_propietario:{clave_inmueble}|{registro.cuenta_propietario}"
        ),
    )


def _normalizar_texto(valor: str) -> str:
    return " ".join(valor.strip().upper().split())


def _fecha(valor: date | None) -> str:
    return valor.isoformat() if valor else ""
