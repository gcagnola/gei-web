from __future__ import annotations

from dataclasses import asdict, dataclass
from datetime import date
from decimal import Decimal


def limpiar_campo(valor: str) -> str:
    return " ".join(valor.strip().split())


@dataclass(frozen=True)
class PropietarioCobol:
    """Registro equivalente a la interpretacion KNG de propietarios.dbf.

    Fuente normativa: fuentes_kng/imp_propietarios.sc2, Command1.Click.
    """

    cuenta: str
    nombre: str
    domicilio: str
    codigo_postal: str
    localidad: str
    provincia: str
    telefono: str
    fecha_ultima_liquidacion: int
    personeria_fiscal: int
    identificacion_fiscal: int
    raw: str

    def to_dict(self) -> dict:
        data = asdict(self)
        data.pop("raw", None)
        return data


@dataclass(frozen=True)
class InquilinoCobol:
    """Registro equivalente a la interpretacion KNG de inquilinos.dbf."""

    cuenta: str
    cuenta_propietario: str
    nombre: str
    domicilio_inmueble: str
    fecha_contrato: date | None
    fecha_vencimiento: date | None
    fecha_baja: date | None
    telefono_particular: str
    telefono_laboral: str
    destino: int
    fecha_inicio: date | None
    tipo_documento: int
    documento: str
    domicilio_legal: str
    codigo_postal: int
    localidad: str
    provincia: str
    personeria_fiscal: int
    identificacion_fiscal: int
    omitido_por_baja_antigua: bool
    raw: str

    def to_dict(self) -> dict:
        data = asdict(self)
        data.pop("raw", None)
        return data


@dataclass(frozen=True)
class MovimientoCuentaCobol:
    archivo: str
    linea: int
    cuenta: str
    fecha: date | None
    numero_movimiento: str
    periodo: str
    concepto: str
    debe: Decimal
    haber: Decimal
    saldo: Decimal | None
    raw: str

    def to_dict(self) -> dict:
        data = asdict(self)
        data.pop("raw", None)
        return data


@dataclass(frozen=True)
class LiquidacionTxt:
    archivo: str
    linea: int
    sede: str
    tipo: str
    fecha: date | None
    cuenta: str
    periodo: str
    numero_de_comprobante: str
    raw: str

    def to_dict(self) -> dict:
        data = asdict(self)
        data.pop("raw", None)
        return data
