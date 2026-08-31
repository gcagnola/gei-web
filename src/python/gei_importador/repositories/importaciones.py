from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

from gei_importador.errores import RepositorioNoEncontradoError


@dataclass(frozen=True)
class RepositorioArchivos:
    repositorio_id: int
    path: Path
    es_compatibilidad_legacy: bool = False

    def archivo_cobol(self, nombre: str) -> Path:
        return self.path / nombre


class RepositorioImportacionesLaravel:
    """Localiza archivos subidos por Laravel.

    La version actual de Laravel guarda COBOL en liquidaciones/cobol sin una
    carpeta por lote. Se prueban primero rutas por repositorio_id para la
    interfaz esperada y se conserva fallback legacy mientras Laravel no tenga
    el modelo de lote definitivo.
    """

    def __init__(self, liquidaciones_base: Path) -> None:
        self.liquidaciones_base = liquidaciones_base

    def obtener(self, repositorio_id: int) -> RepositorioArchivos:
        candidatos = [
            self.liquidaciones_base / "repositorios" / str(repositorio_id) / "cobol",
            self.liquidaciones_base / "repositorios" / str(repositorio_id),
            self.liquidaciones_base / "lotes" / str(repositorio_id) / "cobol",
            self.liquidaciones_base / "lotes" / str(repositorio_id),
            self.liquidaciones_base / str(repositorio_id) / "cobol",
            self.liquidaciones_base / str(repositorio_id),
        ]

        for candidato in candidatos:
            if candidato.is_dir():
                return RepositorioArchivos(repositorio_id, candidato)

        legacy = self.liquidaciones_base / "cobol"
        if legacy.is_dir():
            return RepositorioArchivos(
                repositorio_id,
                legacy,
                es_compatibilidad_legacy=True,
            )

        raise RepositorioNoEncontradoError(
            f"No se encontro repositorio {repositorio_id} en {self.liquidaciones_base}"
        )
