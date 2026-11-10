# Importación documental de contratos GeI

Herramientas utilizadas en GeI-Desarrollo para:

- Importar y normalizar archivos PDF.
- Importar todas las hojas de las planillas XLS.
- Vincular expedientes exclusivamente por cuenta COBOL.
- Recuperar fechas documentales.
- Validar expedientes y verificar archivos PDF.

IMPORTANTE:

Estos scripts corresponden a la implementación inicial en Desarrollo.

Contienen controles específicos de entorno, servidor PostgreSQL
y rutas de archivos.

NO ejecutar directamente en Test ni Producción.

Antes del despliegue deben adaptarse para:

1. Seleccionar explícitamente el entorno.
2. Verificar servidor y base de datos de destino.
3. Configurar las rutas de documentos y manifiestos.
4. Conservar la ejecución reanudable.
5. Impedir sobrescrituras y pérdidas de documentos.
6. Validar la integridad de los PDF.
7. Mantener las identidades por cuenta COBOL.

Los PDF, planillas y manifiestos no deben incorporarse a Git.
