from __future__ import annotations


class ErrorImportador(Exception):
    """Error base del importador."""


class RepositorioNoEncontradoError(ErrorImportador):
    """No se pudo ubicar el repositorio de archivos solicitado."""


class ArchivoCobolFaltanteError(ErrorImportador):
    """Falta un archivo COBOL requerido."""


class ModoNoSoportadoError(ErrorImportador):
    """El modo solicitado todavia no esta implementado para esta fase."""
