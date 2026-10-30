# Cierre de subsanación local

Actualización: 2026-10-30. **100 incidencias subsanadas y verificadas; 0 pendientes.**

El registro activo es [errores.md](../../errores.md). Los 98 hallazgos de flujos y los dos hallazgos adicionales de dependencias se conservan en [el historial](errores-original.md), con [estado y evidencias por incidencia](estado-incidencias.json). No se han descartado incidencias únicamente para hacer pasar pruebas.

## Comprobaciones finales

| Comprobación | Resultado | Evidencia |
|---|---:|---|
| Auditoría de módulos y contraste integral | 268 pruebas, 1507 aserciones | [JUnit](actual.xml) |
| Suite ordinaria del proyecto | 249 pruebas, 1107 aserciones | [JUnit](existente-actual.xml) |
| Total PHP | **517 pruebas; 2614 aserciones; 0 fallos, errores u omisiones** | [Resumen JSON](auditoria-final.json) |
| Navegador Chrome temporal | 24 casos conformes; 8 con React/Inertia instalados y HTTP interceptado | [Inertia real](inertia-real.json), [controles finales](controles-finales-frontend.json) |
| Pruebas unitarias JavaScript | 8 aprobadas | [Salida](frontend-unit.txt) |
| Seguridad de dependencias PHP y JavaScript | 0 avisos en consultas oficiales posteriores a la actualización, incluyendo desarrollo | [Comprobación y bloqueos](dependencias-verificadas.json), [Composer](composer-audit.json), [npm](npm-audit.json) |
| Compilación de producción | Aprobada | [Compilación](build.txt) |
| Formato PHP y comprobación del diff | Aprobados en las fuentes verificadas | [Pint](formato-verificado.txt), [diff](diff-verificado.json) |

La consulta del control ejecuta **13 consultas con 1 expediente y 13 con 26**, manteniendo páginas de 25. CSV y alertas recorren páginas sin recalcular el resumen anual en cada una. Las alertas filtran antes del límite y retienen como máximo 150 resultados. [Medición](rendimiento-control.json).

Se verificaron los 127 paquetes PHP instalados y las 16 dependencias JavaScript directas contra sus archivos de bloqueo. La actualización respeta los rangos compatibles y PHP 8.2; npm se corrigió sin `--force`. Se conservaron los [inventarios y bloqueos anteriores](dependencias-iniciales/). Los avisos oficiales corresponden al momento de consulta, no a una garantía sobre avisos futuros.

## Aplicación local

Se aplicaron cuatro migraciones: devoluciones parciales, nombres de usuario normalizados, estado de captura del presupuesto y vínculo directo de comprobantes con líneas de matriz. Se renovaron las cachés de configuración, rutas y vistas y se regeneró la compilación local. Las huellas anteriores y posteriores confirman la conservación de datos financieros, identidades, campos de acceso de usuarios y comprobantes: [datos financieros y usuarios](datos-locales-conservados.json), [comprobantes y archivos](comprobantes-locales-conservados.json).

Los comprobantes de IC/MAT se vinculan directamente con la línea Final, sin duplicar gastos. Sus permisos, versiones, congelación por el Final correspondiente y almacenamiento privado están probados. Los formularios usan la API de la versión instalada de Inertia. Los selectores admiten búsqueda, selección existente y cursor sin ampliar el alcance autorizado.

Los servicios y consultas separan captura, cálculo, tesorería, conciliación, autorización, comprobantes, envío, respaldo y diagnóstico. La auditoría conserva valores anteriores y nuevos; las revisiones agrupadas requieren visibilidad de todos sus expedientes. El correo distingue fallos locales de resultados ambiguos y verifica los bytes del adjunto antes de HTTP.

## Alcance de los Excel

Los dos originales se mantienen intactos; se volvieron a comprobar sus SHA-256. El control contiene 53 filas físicas, 46 identidades y 7 grupos de alias entre años. Se contrastaron 3588 fórmulas numéricas a centavos mediante un evaluador independiente limitado; 22 expresiones no son evaluables por ese procedimiento. No se ha ejecutado recálculo nativo de Microsoft Excel. [Cobertura y huellas](../integral-audit/resumen-fuentes.json).

El origen conserva una inconsistencia en la hoja **2025, fila 36**: `12668.62 + 1663.58 = 14332.20`, pero el total Initial es `14341.55`, con diferencia de **9.35**. La aplicación la identifica y rechaza como dato incoherente; no elige silenciosamente qué importe sustituir. En **2026!O42** ya existe `#VALUE!` por valores pendientes. Las 14 diferencias frente a reglas generales candidatas permanecen documentadas como observaciones de origen, sin asumir que todas sean errores del software. Los importes agrupados del control tampoco se convierten automáticamente en pagos bancarios. [Referencia de reglas y fórmulas](../integral-audit/resumen-fuentes.json).

## Límites de la validación

Las pruebas PHP, el cifrado y la restauración se ejecutaron con bases aisladas y servicios externos simulados. No se generaron pagos, envíos de correo ni respaldos operativos reales. Las comprobaciones de navegador interceptan HTTP; las 16 adicionales a los 8 casos con Inertia real simulan el contexto de Inertia. No constituyen certificación de rendimiento GPU ni de una entrega real del proveedor.

Se utilizó el árbol existente de Graphify para localizar raíces y dependencias, sin leer indiscriminadamente el proyecto. El grafo original tiene 2652 nodos y 6947 enlaces; las clases nuevas no se presentan como ya reindexadas. [Última navegación acotada](ultima-navegacion.json). El cierre se refiere a las incidencias registradas y a estas comprobaciones, no afirma ausencia absoluta de defectos fuera de ese alcance.
