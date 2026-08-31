from __future__ import annotations

from dataclasses import dataclass, field
from pathlib import Path
from typing import Any


@dataclass
class ErrorRegistro:
    archivo: str
    linea: int
    mensaje: str
    valor: str = ""

    def to_dict(self) -> dict[str, Any]:
        return {
            "archivo": self.archivo,
            "linea": self.linea,
            "mensaje": self.mensaje,
            "valor": self.valor,
        }


@dataclass
class ResumenArchivo:
    nombre: str
    ruta: Path | None = None
    encoding: str | None = None
    sha256: str | None = None
    registros: int = 0
    validos: int = 0
    errores: int = 0
    nuevos: int = 0
    omitidos_ya_importados: int = 0
    actualizados: int = 0
    conflictos: int = 0
    errores_detalle: list[ErrorRegistro] = field(default_factory=list)
    metadata: dict[str, Any] = field(default_factory=dict)

    def to_dict(self) -> dict[str, Any]:
        return {
            "registros": self.registros,
            "registros_leidos": self.registros,
            "validos": self.validos,
            "nuevos": self.nuevos,
            "omitidos_ya_importados": self.omitidos_ya_importados,
            "actualizados": self.actualizados,
            "conflictos": self.conflictos,
            "errores": self.errores,
            "encoding": self.encoding,
            "sha256": self.sha256,
            "ruta": str(self.ruta) if self.ruta else None,
            "metadata": self.metadata,
            "errores_detalle": [e.to_dict() for e in self.errores_detalle[:20]],
        }


@dataclass
class ResultadoImportacion:
    repositorio_id: int
    modo: str
    repositorio_path: Path
    escritura_postgresql: bool = False
    advertencias: list[str] = field(default_factory=list)
    archivos: dict[str, ResumenArchivo] = field(default_factory=dict)
    extra: dict[str, Any] = field(default_factory=dict)

    def to_dict(self) -> dict[str, Any]:
        data = {
            "repositorio_id": self.repositorio_id,
            "modo": self.modo,
            "repositorio_path": str(self.repositorio_path),
            "archivos": {
                nombre: resumen.to_dict()
                for nombre, resumen in self.archivos.items()
            },
            "advertencias": self.advertencias,
            "escritura_postgresql": self.escritura_postgresql,
        }
        data.update(self.extra)

        return data
