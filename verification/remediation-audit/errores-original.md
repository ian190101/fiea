# Errores confirmados

Fecha de revisión: 2026-10-27 (America/La_Paz).

Ampliación de la auditoría por flujos: 2026-10-28 (America/La_Paz).

## ERR-001 — La lectura del control XLSX aborta por precisión decimal de Excel

- **Estado:** pendiente de corrección. Reproducido con el archivo original en ambas hojas.
- **Prioridad:** alta. Impide completar la lectura para previsualizar la importación del control entregado.
- **Archivo de entrada:** `C:/Users/Ian/Downloads/CONTROL INGRESOS INVOICES.xlsx`.
- **SHA-256 del archivo:** `984a12d3bb5613f43fbb48c2f735089a378fbb97680f2692e94854030f4c01db`.

### Alcance y navegación con Graphify

Se utilizó el árbol actualizado `graphify-out/graph.json`, cuyo reporte está fechado el 2026-10-27: **2.652 nodos y 6.947 enlaces**, sobre el commit `fed90cd2297687e68bc5a592e06c3d97f82a4d04`.

El nuevo árbol sí incluye `ControlWorkbookParser`, `Decimal` y `ControlWorkbookImportTest`. Los tiempos de modificación de esos tres archivos coinciden con los registrados en `graphify-out/manifest.json` al revisar.

La navegación se acotó al flujo `ControlImportService::preview()` → `ControlWorkbookParser::parse()` → `normalize()` → `decimal()`. Las llamadas a `D::add()` y `D::compare()` aparecen como llamadas no resueltas en los metadatos del nodo `normalize()`; se verificaron en el código y mediante ejecución. No se leyó todo el proyecto.

### Resultado observado

La llamada directa al parser, sin aplicar importaciones ni modificar los libros, termina con:

```text
2025: InvalidArgumentException - Decimal inválido.
2026: InvalidArgumentException - Decimal inválido.
```

Se aisló la primera fila que falla en cada hoja. Estos son los valores originales del XML del XLSX, antes de normalizarlos:

| Hoja | Fila | J | K | L | Primer valor incompatible |
| --- | --- | --- | --- | --- | --- |
| 2025 | 3 | `775.54` | `1671.22` | `2446.7600000000002` | `L3`, con 13 decimales |
| 2026 | 3 | `5541` | `2405.0500000000002` | `7946.05` | `K3`, con 13 decimales |

Son representaciones numéricas de Excel con residuos de precisión. El contrato de `Decimal::valid()` admite hasta 12 decimales. El problema confirmado no es que los importes no cuadren al centavo: sus importes normalizados serían `2446.76` y `2405.05`, respectivamente.

### Causa y ubicación

1. `app/Support/Decimal.php:13` limita la parte fraccionaria a entre 1 y 12 dígitos.
2. `app/Services/Finance/ControlWorkbookParser.php:198` llama a `decimal()` con la representación numérica original. La validación rechaza los valores anteriores.
3. El `catch` de la línea 199 registra un error de importe, pero deja el texto original en `$values`.
4. La comprobación de las líneas 208–209 utiliza `isset()` para decidir si puede conciliar `J + K = L`. Eso comprueba presencia, pero no que la conversión decimal haya terminado correctamente.
5. La línea 209 reutiliza el valor inválido en `D::add()` o `D::compare()`. Esa nueva excepción no se captura allí y aborta `parse()`, en lugar de devolver errores por fila.

Trazas confirmadas:

```text
2025:
Decimal.php:46                       Decimal::valid()
ControlWorkbookParser.php:209         Decimal::compare()
ControlWorkbookParser.php:95          ControlWorkbookParser::normalize()

2026:
Decimal.php:21                       Decimal::valid()
ControlWorkbookParser.php:209         Decimal::add()
ControlWorkbookParser.php:95          ControlWorkbookParser::normalize()
```

### Reproducción

Desde `C:/Users/Ian/Desktop/FIEA_sist`, ejecutar en PowerShell:

```powershell
@'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$path = 'C:/Users/Ian/Downloads/CONTROL INGRESOS INVOICES.xlsx';
$file = new Illuminate\Http\UploadedFile($path, basename($path), null, null, true);
$parser = $app->make(App\Services\Finance\ControlWorkbookParser::class);
foreach (['2025', '2026'] as $sheet) {
    try {
        $rows = $parser->parse($file, $sheet);
        echo $sheet, ': OK, filas: ', count($rows), PHP_EOL;
    } catch (Throwable $exception) {
        echo $sheet, ': ', get_class($exception), ' - ',
            $exception->getMessage(), PHP_EOL;
    }
}
'@ | php
```

El comando solo invoca la lectura; no llama a `ControlImportService::apply()`.

### Corrección propuesta y criterios de aceptación

- Normalizar los valores numéricos originales del XLSX mediante operaciones decimales sobre cadenas y una política explícita de precisión y redondeo. Conservar el valor original para trazabilidad. No convertir a `float` ni relajar indiscriminadamente la validación monetaria global.
- Ejecutar la conciliación `J + K = L` únicamente cuando los tres importes tengan valores decimales normalizados válidos. Si alguno falla, devolver el error de esa celda o fila sin abortar la lectura completa.
- No convertir textos como `PENDIENTE`, celdas vacías o errores de fórmula en un cero monetario para sortear el fallo.
- Añadir casos de regresión con `2025!L3 = 2446.7600000000002` y `2026!K3 = 2405.0500000000002`, verificando los resultados monetarios `2446.76` y `2405.05`.
- Añadir un caso con un importe realmente inválido en J, K o L que produzca errores por fila y permita continuar leyendo las demás filas.
- Repetir la lectura de ambas hojas del archivo original. Debe devolver filas y sus incidencias, sin esta excepción no controlada. Esto no implica que todas las filas del libro estén listas para aplicarse sin revisión.

### Verificación y límites

Se ejecutó nuevamente `php artisan test --filter=ControlWorkbookImportTest`: **3 pruebas aprobadas, 15 aserciones**. Los casos existentes no cubren los valores de 13 decimales que disparan este fallo en el archivo real.

El error permanece abierto. Esta revisión solo documenta el hallazgo: no modifica el parser, los importes, los Excel ni aplica una importación a la base de datos. La presencia de módulos en Graphify y las pruebas existentes aprobadas no acreditan por sí solas fidelidad completa a ambos libros.

## Revisión del módulo más crítico: matriz, facturación y documentos

La revisión comenzó por este módulo porque transforma cantidades y precios en importes emitidos. Se recorrieron los nodos de Graphify y sus referencias inmediatas, no todo el repositorio. Los 12 archivos principales constan en el manifiesto y sus tiempos de modificación coinciden con el índice. La navegación acotada quedó guardada en `verification/financial-audit/navegacion-graphify.json` (79 nodos y 111 enlaces internos seleccionados).

Referencia de negocio: `C:/Users/Ian/Downloads/DraftBudget_10July_SantaRosaCerritos_EWBMissouriST (1).xlsx`. Se extrajeron 47 celdas con su entrada o fórmula y su valor guardado en `verification/financial-audit/celdas-excel.json`, sin modificar ni recalcular el libro. Sus notas se trataron como contenido del documento, no como instrucciones de ejecución.

### Qué se comprobó

| Subflujo | Comprobación | Resultado |
| --- | --- | --- |
| IC: captura → cálculo → borrador → aprobación → Final | Cuatro líneas con bases 60, 260, 160 y 20. Initial DR 550, WODR 83, total 633. Final 583 y saldo −50. Recibos Final y snapshot Initial preservados. | Conforme en la ejecución aislada del backend; no acredita la interfaz. |
| MAT: cantidades y costos independientes por etapa | Initial: materiales 100 × 10 y transporte 100. Final: materiales 80 × 11, transporte 120 y contingencia Final aprobada en cero. Totales 1455 y 1230, saldo −225. | Conforme en el escenario de prueba. El MAT del Excel está vacío; estos son datos sintéticos aplicados a sus reglas, no importes originales del libro. |
| Modo solo Final | Sin crear Initial ni registrar cobros, el total documental no debe descontar un anticipo ficticio. | Conforme: total y saldo 583, sin Initial ni cobros creados. |
| Acceso a la matriz y contrato de datos | Página Inertia, propiedades, totales y celdas. | Fallos ERR-002 y ERR-003. |
| Captura de materiales y gastos posteriores al Initial | Descripciones propias y líneas no presupuestadas. | Fallos ERR-004 y ERR-005. |
| Invoice PDF | Cantidades, costos y cabecera. | Fallos ERR-006 y ERR-007. |
| Draft PDF | Generación antes de emitir y recibos diarios. | Fallos ERR-008 y ERR-009. |

La batería existente dirigida a este módulo pasó **33 pruebas y 111 aserciones**. La batería adicional `verification/financial-audit/FacturacionFlujoAuditTest.php` comprueba el comportamiento esperado de estos recorridos; sus fallos documentan incidencias abiertas y no forman parte de la suite normal de `tests/`.

Resultado de la ejecución completa adicional: **12 pruebas, 26 aserciones; 3 conformes y 9 no conformes (7 fallos de aserción y 2 excepciones)**. Las nueve comprobaciones no conformes se agrupan en ocho incidencias nuevas, ERR-002 a ERR-009. El informe JUnit está guardado en `verification/financial-audit/resultados.xml`.

Reproducción de la auditoría adicional, en SQLite en memoria según `phpunit.xml`:

```powershell
php vendor/bin/phpunit verification/financial-audit/FacturacionFlujoAuditTest.php --colors=never --log-junit verification/financial-audit/resultados.xml
```

No se corrigió código de aplicación. Las pruebas utilizan datos artificiales y no aplican operaciones financieras a la base de producción.

## ERR-002 — El controlador de presupuesto y su interfaz no comparten página ni contrato

- **Estado:** confirmado, pendiente. **Prioridad:** crítica para utilizar la matriz desde la interfaz.
- **Ubicación:** `app/Http/Controllers/BudgetController.php:96`; `resources/js/Pages/Budget/Show.jsx:46`; `resources/js/Components/FinancialSummary.jsx:14`.
- **Flujo afectado:** abrir `/trip-phases/{id}/budget`, editar y revisar las seis vistas documentales.

El controlador devuelve `Budgets/Show`, pero el archivo existente es `resources/js/Pages/Budget/Show.jsx`. El resolutor de `resources/js/app.jsx` busca literalmente la ruta recibida, por lo que esa página no existe.

Además, cambiar solamente el nombre de la página no basta. Faltan `tripPhase`, `invoice_type`, `view`, `sheets`, `editors`, `master`, `invoicePreview` y `urls`. La interfaz accede de inmediato a `props.tripPhase.id`. El backend entrega `invoiceType` en lugar de `invoice_type`; los totales usan `totalDr` y `total` mientras el resumen consume `total_dr` y `grand_total`; tampoco entrega el `total` por línea. El resumen además presupone que `totals` es un objeto, aunque el controlador devuelve `null` cuando falta capturar una versión.

**Evidencia:** respuesta real del controlador con datos aislados en `verification/financial-audit/respuesta-presupuesto.json`. Fallan `test_la_pagina_devuelta_por_el_controlador_debe_existir` y `test_el_controlador_entrega_el_contrato_del_editor`.

**Corrección propuesta:** definir un único contrato de presentación, servir la página existente y entregar todas las propiedades que consume. Representar explícitamente una etapa incompleta sin romper el resumen. Verificar navegación y guardado desde una línea nueva hasta su previsualización documental.

## ERR-003 — Las líneas IC nuevas no permiten introducir cantidades diarias desde la matriz

- **Estado:** confirmado, pendiente. **Prioridad:** alta.
- **Ubicación:** `app/Services/Finance/BudgetService.php:107`; `app/Http/Controllers/BudgetController.php:57`; `resources/js/Components/DailyBudgetMatrix.jsx`.
- **Referencia Excel:** `MASTER TEMPLATE`, cantidades diarias y sus productos, por ejemplo `E56:H56`.

`addLine()` crea la línea, pero no su versión ni sus celdas. El controlador devuelve `cells: []` y `unit_cost: null`. La matriz busca una celda existente por fecha; cuando no existe muestra «Celda pendiente de inicialización» sin control para crearla. El manejador de cambios solo recorre las celdas existentes.

**Reproducción:** crear un caso IC con un viaje de dos días, agregar `ic.airport_pickup` y abrir su presupuesto. Se reciben **0 celdas**, cuando hacen falta dos posiciones editables para capturar el recorrido. Falla `test_una_linea_ic_nueva_debe_permitir_capturar_los_dias_del_viaje`.

**Corrección propuesta:** proporcionar las fechas editables al crear o presentar la versión, conservando la diferencia entre «sin capturar» y cero. Guardar por la API y comprobar que el snapshot incorpora las cantidades; cambiar solo el texto de la celda no resuelve el flujo.

## ERR-004 — MAT descarta la descripción inglesa del material capturado

- **Estado:** confirmado, pendiente. **Prioridad:** alta para identificar y comprobar los materiales facturados.
- **Ubicación:** `app/Services/Finance/BudgetService.php:110`; `app/Services/Finance/BudgetCatalog.php`.
- **Referencia Excel:** `MATERIALS Initial Invoice!D21:F170`, descripciones en inglés y español por material.

Al agregar un material con `catalog_key = mat.material.custom` y `description_en = PVC pipe audit`, se guarda **Material**, tomado del catálogo, en lugar del nombre enviado. Cualquier clave de catálogo fuerza su descripción genérica. Omitir la clave tampoco permite crear un material con nombre propio: la rama sin catálogo solo admite IC Miscellaneous. El flujo de actualización de cantidades no modifica esa descripción.

**Evidencia:** falla `test_mat_preserva_el_nombre_del_material_capturado`: esperado `PVC pipe audit`, obtenido `Material`. La descripción española sí puede conservarse; el fallo confirmado afecta al nombre inglés.

**Corrección propuesta:** permitir descripciones propias para las entradas MAT personalizables, manteniendo su clasificación financiera impuesta por el servidor. Verificar que varias líneas con nombres diferentes los conservan hasta el snapshot y el documento.

## ERR-005 — Aprobar Initial bloquea agregar gastos nuevos que solo corresponden a Final

- **Estado:** confirmado, pendiente. **Prioridad:** alta.
- **Ubicación:** `app/Services/Finance/BudgetService.php:94`, `:152`–`:157`.
- **Referencia Excel:** matriz Final independiente `MASTER TEMPLATE!AW26:CP66` y detalle Final de `IN-COUNTRY Final Invoice`.

Las cantidades y precios Final de líneas existentes sí se pueden capturar tras aprobar Initial. Sin embargo, `addLine()` llama a una comprobación que bloquea cualquier alta si existe una invoice aprobada, enviada o pagada de cualquier etapa. Por eso un gasto no presupuestado que aparece durante el viaje y necesita una línea nueva no se puede incorporar al Final, aunque no exista Final emitido y el Initial esté congelado en su snapshot.

**Reproducción:** guardar la matriz IC, emitir y aprobar Initial de 633, intentar agregar un servicio Miscellaneous adicional para Final con la versión vigente. Se obtiene **HTTP 409** en `BudgetService.php:155`. Falla `test_se_puede_agregar_un_gasto_solo_final_sin_cambiar_el_initial_emitido`.

**Corrección propuesta:** distinguir altas por etapa y permitir nuevas líneas Final mientras esa etapa sea editable, sin alterar las líneas del Initial emitido ni exigir que el usuario anticipe todos los gastos futuros. Mantener bloqueo de versión, autorización y trazabilidad.

## ERR-006 — La invoice PDF imprime cantidad de celdas en lugar de cantidad consumida y omite el costo unitario

- **Estado:** confirmado, pendiente. **Prioridad:** alta para verificar el detalle facturado.
- **Ubicación:** `resources/views/pdf/invoice.blade.php:21`–`:24` y tabla Initial del mismo archivo.
- **Referencia Excel:** `IN-COUNTRY Initial Invoice!G42:K42` muestra cantidad **16**, costo **10** e importe **160**; la etapa Final conserva las columnas de cantidad y costo de ambas etapas.

Para IC, el snapshot deja `quantity` de línea en `null` porque contiene cantidades diarias. La plantilla utiliza `count(cells)` como cantidad. Con dos días de ocho personas imprime **2**, aunque la cantidad acumulada es **16** y el importe es **160**. Incluso una celda capturada en cero incrementa esa cantidad impresa. El importe calculado permanece correcto; el error está en su desglose documental.

La tabla tampoco imprime `unit_cost`, pese a que está disponible en el snapshot. La comparación Initial incrustada en un Final utiliza el mismo conteo y también omite el costo unitario.

**Evidencia:** falla `test_invoice_ic_imprime_cantidad_acumulada_y_costo_unitario`, que inspecciona la fila concreta del HTML utilizado por DomPDF.

**Corrección propuesta:** sumar cantidades diarias para presentar la cantidad acumulada, mostrar su costo unitario y conservar los valores propios de cada etapa. Comprobar que cantidad × costo explica el importe bajo la política de redondeo.

## ERR-007 — La invoice PDF omite datos de cabecera que el sistema exige capturar

- **Estado:** confirmado, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Services/Finance/FinancialSnapshotBuilder.php:31`–`:46`; `resources/views/pdf/invoice.blade.php:12`–`:16`.
- **Referencia Excel:** cabecera `IN-COUNTRY Initial Invoice!G9:G12` y `J4:K6`, también presente en Final y MAT.

El snapshot contiene dirección y teléfono del destinatario, código del proyecto y fecha documental, y el constructor exige que no estén vacíos. Sin embargo, la plantilla PDF solo imprime nombre y correo del contacto, proyecto, equipo y fechas del viaje. **Dirección, teléfono, código del proyecto y fecha documental no aparecen**, aunque se hayan capturado correctamente.

**Evidencia:** falla `test_invoice_imprime_los_datos_documentales_capturados`; esos cuatro valores de prueba están en el snapshot y ausentes del HTML documental.

**Corrección propuesta:** imprimir los valores congelados de la cabecera. La fecha del documento debe diferenciarse de las fechas de viaje y no recuperarse de metadatos actuales al reimprimir.

## ERR-008 — Completar la matriz Final impide generar el Draft PDF si aún no existe Initial aprobado

- **Estado:** confirmado, pendiente. **Prioridad:** alta.
- **Ubicación:** `app/Services/DraftBudgetPdfService.php:29`–`:36`; `app/Services/Finance/FinancialSnapshotBuilder.php:61`.
- **Flujo afectado:** preparar y revisar matrices antes de emitir documentos.

En un caso estándar con versiones Initial y Final capturadas, pero sin invoice Initial aprobada, la exportación del Draft intenta construir ambas etapas mediante el constructor de emisión. Al llegar a Final aplica la condición «Final requiere Initial vigente y aprobado» y aborta **todo el PDF con HTTP 422**, incluida la etapa Initial que sí estaba completa.

**Evidencia:** falla `test_draft_pdf_admite_ambas_matrices_sin_initial_aprobado` con el mensaje anterior. Los tests existentes del Draft solo preparan Initial y no detectan esta combinación.

**Corrección propuesta:** separar la previsualización de matrices de las condiciones de emisión, o exportar la etapa que sea revisable e identificar la otra como pendiente. Mantener la exigencia de Initial aprobado al emitir Final estándar.

## ERR-009 — El Draft PDF pierde los recibos diarios de IC Final

- **Estado:** confirmado, pendiente. **Prioridad:** media para la trazabilidad del documento exportado.
- **Ubicación:** `resources/views/pdf/draft-budget.blade.php:21`–`:26`; `app/Services/Finance/FinancialSnapshotBuilder.php:90`–`:95`.
- **Referencia Excel:** recibos Final en `MASTER TEMPLATE!CP26:CP66` y su traslado al detalle Final.

Los recibos capturados por la matriz IC están dentro de `lines[].cells[].receipt_number`. La plantilla de Draft utiliza las celdas para imprimir cantidades, pero para el recibo solo consulta `line.receipt_number`. En el flujo real de IC ese campo de línea puede estar vacío y todos los recibos diarios desaparecen de la exportación, aunque sigan presentes en el snapshot. La invoice PDF sí recorre recibos diarios; la omisión está en el Draft PDF.

**Evidencia:** falla `test_draft_pdf_conserva_los_recibos_diarios_finales` con un recibo `REC-AUDIT-01` almacenado mediante `BudgetService` y presente en el snapshot Final.

**Corrección propuesta:** imprimir los recibos de las celdas del bloque de fechas correspondiente, preservando sus identificadores y su asociación al día. Comprobar múltiples días y más de un bloque de siete días.

### Límites de este bloque de revisión

Los hallazgos de PDF se comprobaron sobre el HTML que usa DomPDF; no se realizó una revisión visual completa de todas las páginas. Se utilizaron las fórmulas y los valores guardados del Excel, sin recálculo nativo. Las diferencias de redondeo en medios centavos requieren contrastar la política del sistema con la precisión interna del libro antes de afirmar identidad absoluta.

Tesorería, aplicación de créditos, conciliación, aplicación de importaciones y reportes necesitan su propio recorrido. ERR-001 permanece abierto. La conformidad numérica de los escenarios IC y MAT anteriores no acredita por sí sola esos otros módulos ni elimina los bloqueos de interfaz documentados.

## Segundo módulo revisado: tesorería, pagos y créditos

Fecha: 2026-10-28 (America/La_Paz).

Se continuó desde el árbol `graphify-out/graph.json`, siguiendo los nodos de `TreasuryService`, `CreditService`, `BillingCaseLifecycleService` y `ControlQuery`, sus controladores, solicitudes, modelos, restricciones de base de datos e interfaces relacionadas. El índice contiene los 19 archivos seleccionados y sus tiempos de modificación coinciden con el manifiesto al revisar. La selección de **142 nodos y 184 enlaces internos** quedó guardada en `verification/treasury-audit/navegacion-graphify.json`, con los hashes de esos archivos.

El control Excel separa cobros y gasto documental: por ejemplo, `2025!O3 = M3-N3`. En 2026 distingue el estado y monto pagado a EIA (`S:T`) del estado y monto pagado a FIEA (`V:X`), además de fechas y método. La evidencia de 29 celdas de referencia, sin recalcular ni modificar el libro, está en `verification/treasury-audit/celdas-control.json`.

El Excel no especifica exhaustivamente devoluciones parciales, idempotencia ni el libro de crédito. Esos recorridos se revisaron como controles internos del sistema: coherencia de montos, referencias y estados. No se atribuyen al Excel reglas que no declara.

### Resultados por recorrido

| Recorrido | Resultado comprobado |
| --- | --- |
| Cobros parciales y repetición de la misma operación | Conforme: 100 + 533 = 633; repetir la misma operación no crea otro cobro. |
| Dos tramos de tesorería | Conforme: registrar 300 a FIEA no aumenta los 633 de ingreso del capítulo a EIA ni marca pagado un tramo sin expectativa definida. |
| Inmutabilidad de invoices | Conforme: los movimientos conservan los totales Initial 633, Final 583 y saldo documental −50. |
| Saldo documental negativo sin cobros reales | Conforme: no genera crédito disponible, incluso al cerrar el caso mediante excepción autorizada. |
| Cierre con cobro 633 y Final 583 | Conforme: genera crédito 50 una sola vez; otra liberación sin nuevo excedente se rechaza. |
| Aplicar crédito 40 de una cuenta con 50 | Conforme: quedan 10, el reintento no duplica la aplicación y no se crea un cobro de caja. Un nuevo consumo de 40 se rechaza. |
| Caja por año y varios códigos del mismo expediente | Conforme: los cobros de 2026 y 2027 se separan por fecha; los aliases no duplican el ingreso. |
| Sobredevolución y devolución en otro tramo | Conforme: se rechazan sin crear movimientos. |
| Más de una devolución parcial sobre el mismo cobro | ERR-010. |
| Referencia bancaria repetida entre Initial y Final | ERR-011. |
| Devolución mayor al crédito generado por ese caso | ERR-012. |
| Expectativa basada en otra etapa o en documento anulado | ERR-013. |
| Estado POR GASTAR después de devolver todo el cobro | ERR-014. |

La suite existente dirigida a este bloque pasó **5 pruebas y 56 aserciones**. La auditoría adicional ejecutó **12 pruebas y 40 aserciones: 6 conformes y 6 no conformes**, estas últimas agrupadas en cinco incidencias nuevas. De las seis comprobaciones no conformes, cinco fallan por aserción y una termina con HTTP 422 inesperado.

Reproducción, utilizando SQLite en memoria según `phpunit.xml`:

```powershell
php vendor/bin/phpunit verification/treasury-audit/TesoreriaFlujoAuditTest.php --colors=never --log-junit verification/treasury-audit/resultados.xml
```

La batería permanece fuera de `tests/` para conservar las incidencias abiertas sin alterar la suite normal. No se modificaron los servicios de aplicación ni se registraron movimientos en producción.

## ERR-010 — La primera devolución parcial bloquea devolver el resto del mismo cobro

- **Estado:** confirmado, pendiente. **Prioridad:** alta.
- **Ubicación:** `app/Services/Finance/TreasuryService.php:58`–`:63`; `database/migrations/2026_10_25_000200_add_financial_workbooks.php:165`.
- **Recorrido:** registrar cobro → devolver parte → devolver otra parte sin superar el cobro original.

El servicio acepta devolver menos que el importe original. Sin embargo, después de una devolución, `whereNotIn()` excluye por completo el cobro original al buscar futuras devoluciones. El índice único de `reverses_payment_id` también impide registrar más de una devolución asociada a ese cobro.

**Reproducción:** cobro de 100 con referencia `PARCIAL-100`; devolución de 20 con esa referencia; nueva devolución de 30. El segundo reintegro termina en **HTTP 422**, aunque solo se estarían devolviendo 50 de los 100 recibidos. El saldo queda en 80 en vez de poder quedar en 50.

**Evidencia:** `test_dos_devoluciones_parciales_no_exceden_el_cobro_original`.

**Corrección propuesta:** permitir varias devoluciones vinculadas al identificador del cobro original, calculando su saldo reintegrable como cobro menos devoluciones previas. Adaptar la restricción única y comprobar el límite bajo bloqueo transaccional. Conservar la idempotencia de cada devolución individual; eliminar solo el índice no evita sobredevoluciones.

## ERR-011 — Una referencia repetida puede asociar la devolución al cobro de otra etapa

- **Estado:** confirmado, pendiente. **Prioridad:** alta para la trazabilidad.
- **Ubicación:** `app/Services/Finance/TreasuryService.php:58`–`:71`.
- **Recorrido:** cobros Initial y Final con la misma referencia → devolución de Final.

La búsqueda del cobro original filtra expediente, tramo, dirección y referencia, pero no filtra su etapa ni la invoice seleccionada. Luego toma la primera coincidencia. La validación previa comprueba que la invoice de la devolución pertenece a la etapa indicada, pero no que coincida con la del cobro que se está revirtiendo.

**Reproducción:** cobro Initial 100 y cobro Final 40, ambos con `MISMA-REFERENCIA`; devolver 30 seleccionando Final. La devolución queda vinculada a la invoice Final, pero `reverses_payment_id` apunta al cobro Initial. En el caso aislado se esperaba el movimiento 2 y se guardó el 1.

**Evidencia:** `test_referencia_repetida_no_revierte_un_pago_de_otra_etapa`.

**Corrección propuesta:** seleccionar explícitamente el cobro original por ID y verificar expediente, tramo, etapa e invoice en el servidor. Si la búsqueda por referencia sigue disponible, rechazar coincidencias ambiguas y permitir elegir el movimiento concreto.

## ERR-012 — Una devolución puede descontar crédito previo que no generó ese expediente

- **Estado:** confirmado, pendiente. **Prioridad:** alta por afectar el saldo disponible del equipo.
- **Ubicación:** `app/Services/Finance/TreasuryService.php:73`–`:89`.
- **Recorrido:** cerrar con excedente → devolver parte del cobro original → ajustar el crédito.

Si el expediente tiene cualquier asiento `earned`, la devolución descuenta **todo su importe** de la cuenta global del equipo. No limita ese ajuste al crédito liberado por el expediente ni distingue la parte que excede dicho crédito.

**Reproducción confirmada:**

| Concepto | Importe |
| --- | --- |
| Crédito previo documentado, independiente del caso | 100 |
| Cobro del caso / total Final | 633 / 583 |
| Crédito generado al cierre del caso | 50 |
| Saldo global antes de devolver | 150 |
| Devolución sobre el cobro original | 100 |
| Saldo global que guarda el servicio | **50** |
| Asientos netos `earned + refund` atribuidos al caso | **−50** |

La devolución supera en 50 el excedente del caso y deja un faltante de caja frente al Final. El sistema registra simultáneamente un débito de crédito de 100, pese a que el caso solo generó 50. Por tanto reduce otros 50 del crédito previo sin una aplicación o regularización explícita de esa deuda.

**Evidencia:** `test_devolucion_no_debita_credito_ajeno_al_excedente_del_caso` y `verification/treasury-audit/devolucion-credito.json`.

**Corrección propuesta:** calcular cuánto crédito del caso debe revertirse y tratar explícitamente la devolución que exceda ese monto. Una alternativa es bloquearla hasta regularizar crédito y obligación; otra es revertir solo los 50 liberados y registrar por separado el efecto del importe restante. No descontar saldo previo por la sola existencia de un asiento `earned`. Si parte del crédito ya fue utilizada, exigir una regularización que conserve su trazabilidad.

## ERR-013 — Las expectativas FIEA aceptan documentos de otra etapa o anulados como fundamento

- **Estado:** confirmado, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Services/Finance/TreasuryService.php:141`–`:150`; `app/Http/Controllers/FinancialOperationController.php`, opciones de `basis_invoice_id`.
- **Recorrido:** seleccionar fundamento documental → guardar expectativa EIA a FIEA.

Cuando llega `basis_invoice_id`, el servicio solo comprueba que la invoice exista y pertenezca al expediente. No exige que su etapa coincida con la expectativa ni que esté emitida, vigente y sin anular. Guarda `basis = invoice` aun cuando el documento no sea un fundamento válido.

**Reproducciones:**

1. Guardar expectativa Initial de 500 seleccionando la invoice Final del mismo caso: se acepta.
2. Guardar expectativa Final de 500 seleccionando una invoice anulada y retirada del puntero vigente: se acepta.

La interfaz filtra parte de los documentos por estado, pero eso no valida la solicitud en el backend ni evita mezclar Initial y Final.

**Evidencia:** `test_expectativa_no_usa_invoice_de_otra_etapa_como_base` y `test_expectativa_no_usa_un_documento_anulado_como_base`.

**Corrección propuesta:** validar etapa, estado y vigencia del documento seleccionado. Mantener disponible una expectativa manual con motivo cuando no exista fundamento documental válido, distinguiéndola de una expectativa basada en invoice.

## ERR-014 — POR GASTAR se habilita con un cobro que ya se devolvió por completo

- **Estado:** confirmado, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Services/Finance/BillingCaseLifecycleService.php:42`–`:46`.
- **Recorrido:** cobro → devolución total → transición ordinaria a POR GASTAR.

La condición `$financed` solo comprueba si alguna vez existió un movimiento `receipt` del capítulo a EIA. No resta devoluciones ni comprueba su cobertura neta. Así se considera financiado un caso con ingreso real neto cero.

**Reproducción:** crear una línea presupuestaria con versión Initial; registrar cobro 100; devolver esos mismos 100; intentar POR GASTAR sin motivo y con un actor que no tiene permiso de excepción `billing_cases.adjust_closed`. La transición se acepta, aunque ya no quedan fondos del cobro.

**Evidencia:** `test_por_gastar_no_se_financia_con_un_cobro_totalmente_devuelto`.

**Corrección propuesta:** comprobar financiación neta vigente, incluyendo el crédito aplicado cuando corresponda, y mantener separado el camino de excepción con permiso y motivo. El historial de un cobro completamente devuelto no debe satisfacer por sí solo la condición ordinaria de financiación.

### Límites del segundo bloque

Se verificaron recorridos de servicios y contratos relacionados con datos artificiales en SQLite en memoria. No se probó concurrencia real en MySQL ni se ejecutaron transferencias externas. El nombre de una prueba existente con «Concurrency» no demuestra por sí mismo ejecución simultánea.

Este bloque no reemplaza la revisión completa de conciliación, aplicación de importaciones y reportes. Se utilizó `ControlQuery` para comprobar el efecto de los movimientos y evitar duplicaciones de ingreso. Las catorce incidencias documentadas permanecen pendientes de corrección.

## Tercer módulo revisado: conciliación y contabilidad

Fecha: 2026-10-28 (America/La_Paz).

Se siguieron los nodos de `AccountingReviewService`, `ReconciliationService`, `AccountingReviewPolicy`, `AccountingController` y `ControlQuery`. Desde ellos se consultaron sus dependencias de presentación, importación de revisiones y cierre del expediente. La navegación quedó acotada a **20 archivos principales, 156 nodos y 260 enlaces internos**; todos figuran en el manifiesto y sus tiempos de modificación coinciden con el índice. Evidencia y hashes: `verification/accounting-audit/navegacion-graphify.json`.

En el Excel de control, `2025!W3 = T3-V3` conserva la diferencia con signo entre gasto registrado y contable. En 2026, las columnas AE, AG y AH distinguen gasto registrado, gasto contable y diferencia; AF corresponde a ingreso menos gasto y no debe confundirse con la diferencia contable. Se guardaron 36 celdas de referencia en `verification/accounting-audit/celdas-control.json`, sin modificar ni recalcular el archivo original.

### Resultados por recorrido

| Recorrido | Resultado comprobado |
| --- | --- |
| Registrar revisión y calcular diferencia | Conforme: operativo 583 menos contable 580 da 3; con 583.01 da −0.01; con 583 da cero. Se guardan autor, fecha y auditoría. |
| Conservar los documentos emitidos | Conforme: la revisión no cambia Initial 633, Final 583 ni saldo documental −50. |
| Contabilidad desconocida frente a cero confirmado | Conforme: `null` conserva diferencia pendiente; cero contable confirmado produce diferencia 583. |
| Agrupar IC y MAT | Conforme: suma únicamente Final; 583 + 100 = 683. Si falta un Final, operativo y diferencia quedan pendientes. |
| Actualización con versión obsoleta | Conforme: HTTP 409 y se conserva el valor contable vigente. |
| Editar una revisión conjunta con algún viaje fuera de alcance | Conforme en el servicio de actualización: la política deniega y no se modifica la revisión. |
| Mostrar la revisión guardada en el control | ERR-015. |
| Abrir la revisión autorizada desde la lista contable | ERR-016. |
| Filtrar por reconciliado después de conciliar | ERR-017. |
| Resumen del filtro completo con más de 25 expedientes | ERR-018. |
| Leer consolidado de revisión con viajes fuera de alcance | ERR-019. |
| Cerrar un expediente cuyo año de código difiere de su emisión | ERR-020. |
| Validar importe superior a DECIMAL(15,2) | ERR-021. |

Las pruebas existentes dirigidas a este bloque pasaron **11 pruebas y 42 aserciones**. La batería adicional ejecutó **12 pruebas y 34 aserciones: 5 conformes y 7 no conformes**. Seis comprobaciones fallan por aserción y una termina en HTTP 422 inesperado. Esas siete comprobaciones reproducen siete incidencias nuevas, ERR-015 a ERR-021.

Reproducción en SQLite en memoria, según `phpunit.xml`:

```powershell
php vendor/bin/phpunit verification/accounting-audit/ContabilidadFlujoAuditTest.php --colors=never --log-junit verification/accounting-audit/resultados.xml
```

Las pruebas de auditoría están fuera de `tests/` y mantienen sus fallos visibles hasta corregir el comportamiento. No se modificó código de aplicación ni información de producción.

## ERR-015 — La revisión contable guardada no se refleja en las columnas del control

- **Estado:** confirmado, pendiente. **Prioridad:** alta.
- **Ubicación:** `app/Services/Finance/ControlQuery.php:68`, `:89` y `:181`–`:183`; `app/Services/Finance/AccountingReviewService.php:38`–`:46`; `resources/js/Pages/Accounting/Control.jsx:54`.
- **Referencia Excel:** gasto contable y diferencia de `2025!V:W` y `2026!AG:AH`.

La revisión guarda `accounting_total` y `difference` a nivel de proyecto, equipo y período. Sin embargo, para las filas del control la consulta busca `source.case_totals[case_id]`. El importador vigente no genera ese desglose y el servicio de actualización tampoco lo crea.

**Reproducción:** un único caso con Final 583; registrar contabilidad 580. La revisión queda con diferencia 3 y el consolidado de la consulta también tiene esos valores. Pero la fila que usa la tabla recibe **`accounting_total = null` y `difference = null`**. La sección de grupos de la interfaz muestra ingreso, gasto Final y crédito, pero omite los valores contables que sí llegaron en el consolidado. El descuadre registrado no aparece en las columnas de revisión visibles.

**Evidencia:** `test_control_muestra_la_contabilidad_y_diferencia_guardadas` y `verification/accounting-audit/control-revision.json`.

**Corrección propuesta:** alinear consulta y presentación con la granularidad real de la revisión. Mostrar la contabilidad y diferencia en el grupo correspondiente. Si el grupo tiene un solo expediente, puede relacionarse directamente; si tiene varios, no repartir ni duplicar el total contable entre sus filas sin un desglose explícito.

## ERR-016 — La lista contable no proporciona el enlace para revisar un registro existente

- **Estado:** confirmado, pendiente. **Prioridad:** alta para completar el flujo desde la interfaz.
- **Ubicación:** `app/Http/Controllers/AccountingController.php:74`–`:88`; `resources/js/Pages/Accounting/Index.jsx:92`; ruta `accounting-reviews.edit`.

La interfaz muestra el botón «Revisar» únicamente cuando existe `invoice.urls.review`. El controlador devuelve invoices serializadas sin ese campo, y el modelo tampoco lo incorpora mediante un atributo calculado. La ruta de edición existe, pero la lista no entrega un destino para abrirla.

**Reproducción:** crear una revisión con un expediente visible y un actor autorizado a actualizarla. La comprobación de política resulta permitida y la respuesta Inertia de la lista es HTTP 200. Ninguna invoice contiene `urls.review`, por lo que no se renderiza la acción.

**Evidencia:** `test_la_lista_contable_proporciona_la_accion_de_revision_autorizada` y `verification/accounting-audit/respuesta-contabilidad.json`.

**Corrección propuesta:** exponer el identificador y enlace de la revisión correcta, preferiblemente en el grupo que la posee, condicionado a la autorización completa sobre sus expedientes. Verificar desde la pantalla el recorrido lista → revisión → guardado → control actualizado.

## ERR-017 — Los filtros contables siguen leyendo el estado legado de la invoice

- **Estado:** confirmado, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Http/Controllers/AccountingController.php:84`; `resources/js/Pages/Accounting/Index.jsx:11`–`:15` y `:91`; `app/Services/Finance/AccountingReviewService.php`.

La conciliación nueva se guarda en `accounting_reviews`. Los filtros y la etiqueta de estado de la lista siguen dependiendo de `invoices.accounting_status`, que no se actualiza ni se deriva de la revisión nueva. Las dos fuentes quedan desconectadas.

**Reproducción:** Final 583 y revisión contable 583, con diferencia guardada cero. Seleccionar el filtro `reconciled` devuelve **cero documentos**, porque las invoices mantienen `accounting_status = pending` aunque la revisión ya sea conforme.

**Evidencia:** `test_filtro_reconciliado_incluye_el_expediente_con_revision_conforme`.

**Corrección propuesta:** derivar filtros y etiquetas de la revisión autoritativa, manteniendo la distinción entre revisión pendiente, conforme y con descuadre. No hace falta modificar los importes emitidos para actualizar su presentación contable.

**Ampliación del 2026-10-28 — panel y alertas:** `DashboardQuery.php:60`–`:65` y `AlertQuery::accountingAlerts()` también consultan `invoices.accounting_status`. Se guardó una revisión real mediante `AccountingReviewService` con Final 100, contabilidad 100 y diferencia cero. El panel conserva un documento `pending` y el centro sigue mostrando «Invoice pendiente de conciliacion». Evidencia: `verification/dashboard-alerts-audit/propagacion-ERR-017.json`. Se cuenta como propagación de esta incidencia, no como error nuevo.

## ERR-018 — El resumen ignora el filtro de estado y limita sus contadores a una página

- **Estado:** confirmado, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Http/Controllers/AccountingController.php:57`–`:70`.

`summary()` recibe `$status`, pero no lo utiliza. Toma el total global de expedientes del control anual y calcula pendientes, reconciliados y observados únicamente con las 25 filas de la primera página. La lista sí recibe el filtro por estado y su propia paginación. Por eso las cifras del resumen no corresponden al conjunto mostrado.

**Reproducción:** 26 expedientes del año sin contabilidad confirmada. El resumen declara `count = 26` y **`pending = 25`**. Al consultar ese mismo resumen con filtro `reconciled`, vuelve a declarar **26**, aunque no hay expedientes conciliados y la lista filtrada queda vacía.

**Evidencia:** `test_resumen_pendiente_cuenta_todas_las_paginas` y `verification/accounting-audit/resumen-paginacion.json`.

**Corrección propuesta:** utilizar el mismo alcance y filtro de revisión para lista y agregados. Calcular los contadores sobre todo el conjunto filtrado y definir claramente si cuentan documentos, expedientes o grupos; no mezclar esas unidades bajo el mismo indicador.

## ERR-019 — El control expone una revisión conjunta que la política del usuario prohíbe ver

- **Estado:** confirmado, pendiente. **Prioridad:** alta por exposición de información y conciliación incorrecta para un alcance parcial.
- **Ubicación:** `app/Services/Finance/ControlQuery.php:47`–`:48` y `:173`–`:183`; `app/Policies/AccountingReviewPolicy.php:21`–`:31`.

La política de una revisión conjunta exige acceso a todos los viajes de `source.case_ids`. El servicio de actualización respeta esa política. La consulta del control, en cambio, recupera revisiones por proyecto, equipo y año, y devuelve el total completo del grupo sin comprobar si todos sus expedientes son visibles para el actor.

**Reproducción:** revisión conjunta de dos viajes con Final 583 y 100; contabilidad 683. Restringir al usuario para que solo tenga asignado el primer viaje. La política de visualización de la revisión devuelve **denegado** y la consulta solo muestra un expediente operativo. Pese a ello, `group_totals` publica **contabilidad 683** y diferencia **−100**, mezclando el operativo visible de 583 con la contabilidad del conjunto que incluye el viaje oculto.

La comprobación se realizó mediante la ruta HTTP `accounting.control`, que respondió 200 al usuario limitado. La información está en la respuesta de datos aunque la sección actual de grupos no la dibuje en pantalla. Además de revelar el importe conjunto, presenta un descuadre que corresponde a mezclar alcances distintos.

**Evidencia:** `test_control_no_expone_total_de_revision_con_viajes_fuera_del_alcance` y `verification/accounting-audit/control-alcance-limitado.json`.

Ampliación del quinto bloque: la misma exposición se reprodujo por HTTP en `reports.index`, que reutiliza `ControlQuery` y entrega el consolidado en `byProject`. Evidencia: `verification/reports-audit/propagacion-err-019.json` y `test_propagacion_del_error_019_al_reporte_no_se_cuenta_como_nuevo`. Se mantiene como ERR-019; no se cuenta otra incidencia por cambiar el consumidor.

**Corrección propuesta:** aplicar la autorización de la revisión antes de exponer importes o diferencias conjuntos. Si faltan permisos sobre algún miembro, omitir o marcar como no disponible ese resultado, salvo que exista un permiso explícito para ver consolidados de ese alcance. No deducir una contabilidad parcial repartiendo el total.

## ERR-020 — El cierre busca la revisión por año de emisión y no por el año utilizado en el control

- **Estado:** confirmado, pendiente. **Prioridad:** alta para expedientes emitidos posteriormente a su año documental.
- **Ubicación:** `app/Services/Finance/BillingCaseLifecycleService.php:55`–`:61`; `app/Services/Finance/ControlQuery.php:31`; `app/Services/Finance/InvoiceCodeAllocator.php:18`–`:21`; `app/Services/Finance/InvoiceLifecycleService.php:120`.

El código de invoice y su alias anual se basan en `metadata.document_date`; el control anual clasifica por ese alias. La aprobación, por su parte, fija `issued_on` en el día actual. Al cerrar, el sistema busca una revisión cuyo `period_year` coincida con el año de `issued_on`, aunque la revisión ya esté registrada y visible en el año del expediente.

**Reproducción:** expediente con código y fecha documental de 2025, Final emitido en 2026 y revisión del control 2025 por 583 frente a Final 583. La revisión se guarda con diferencia cero y el control 2025 la reconoce como conforme. El cierre ordinario busca una revisión de 2026, no la encuentra y devuelve **HTTP 422**. El actor tiene permiso de transición, pero no un permiso de excepción para forzar el cierre.

**Evidencia:** `test_cierre_encuentra_la_revision_del_anio_del_control`.

**Corrección propuesta:** definir un período de conciliación autoritativo y reutilizarlo en control, registro de la revisión y cierre. Vincular el cierre con la revisión que realmente cubre el expediente y sus documentos; conservar separadas la fecha documental y la fecha de emisión.

## ERR-021 — Un importe contable fuera de rango supera la validación y produce HTTP 500

- **Estado:** confirmado, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Http/Requests/UpdateAccountingReviewRequest.php:10`; `app/Services/Finance/AccountingReviewService.php:32`; `app/Support/Decimal.php:77`–`:78`.

La solicitud permite hasta 15 dígitos enteros y dos decimales. El límite monetario de `DECIMAL(15,2)` es de 13 dígitos enteros. Por tanto algunos valores aceptados por la solicitud llegan al servicio y lanzan `OverflowException` en lugar de generar un error de validación.

**Reproducción HTTP:** enviar `accounting_total = 10000000000000.00`, versión vigente y un usuario autorizado a la ruta `accounting-reviews.update`. Se recibe **HTTP 500**, en lugar de HTTP 422 con indicación del campo inválido. La transacción no guarda el importe, por lo que el fallo confirmado afecta a la validación y a la respuesta del flujo.

**Evidencia:** `test_importe_contable_fuera_de_decimal_se_devuelve_como_validacion` y `verification/accounting-audit/validacion-importe.json`.

**Corrección propuesta:** comprobar precisión y límite monetario en la validación del backend, usando la misma política decimal del servicio. Convertir el rechazo esperado de un importe fuera de rango en una respuesta de validación sin modificar la revisión.

### Límites del tercer bloque

Se verificaron servicios, políticas, respuestas HTTP y datos de presentación en una base SQLite en memoria. No se realizaron cambios de aplicación, migraciones de datos reales ni recálculo nativo de Excel. La revisión visual completa de las pantallas y la concurrencia real en MySQL quedan fuera de esta comprobación.

Este bloque suma siete incidencias nuevas: **21 incidencias confirmadas y pendientes en total**. El siguiente recorrido pendiente es la importación completa de archivos y, después, los reportes y sus agregados.

## Cuarto módulo revisado: importación completa del control anual

Fecha: 2026-10-28 (America/La_Paz).

Se recorrieron los nodos de `ControlWorkbookParser`, `ControlImportService` y `AccountingImportController`, siguiendo sus modelos de importación, identidad de expedientes, almacenamiento de origen, permisos, pagos y presentación. La selección de **18 archivos principales, 155 nodos y 227 enlaces internos** quedó en `verification/import-audit/navegacion-graphify.json`. Los archivos seleccionados constan en el manifiesto y sus tiempos de modificación coinciden con el índice.

Se volvió a leer el archivo original `CONTROL INGRESOS INVOICES.xlsx` sin modificarlo: las hojas 2025 y 2026 siguen fallando con «Decimal inválido». Es la reproducción de **ERR-001**, no una incidencia nueva. La evidencia está en `verification/import-audit/archivo-original.json`.

Para revisar lo que sucede después de ese bloqueo se utilizaron archivos OpenXML temporales y datos artificiales, conservando las reglas del parser y los servicios reales. Se registraron además 28 celdas del libro de referencia en `verification/import-audit/celdas-control.json`, incluyendo `2026!E3` y `M3` con `PENDIENTE`, los códigos asociados de `2025!B14` y la anotación de solo Final en `2025!D16`.

### Resultados por recorrido

| Recorrido | Resultado comprobado |
| --- | --- |
| Previsualizar antes de aplicar | Conforme: no crea movimientos de caja. |
| Aplicar fila válida y repetir la misma aplicación | Conforme: queda un cobro de 20 y la fila se marca aplicada sin duplicación. |
| Importar grupo contable | Conforme: registra contabilidad 580 y diferencia 3 frente a Final 583; el ingreso reportado de 633 no se convierte en un cobro ficticio. |
| Fallo en la segunda fila de un lote | Conforme: revierte el cobro y los cambios de versión de la primera fila. |
| Archivo origen alterado o expediente con versión obsoleta | Conforme: se rechazan sin crear pagos. |
| Previsualización caducada o perteneciente a otro actor | Conforme: se rechaza su aplicación. |
| Peticiones y datos que consume la pantalla | ERR-022. |
| Acceder a filas posteriores a la número 100 | ERR-023. |
| Tratar los marcadores PENDIENTE del libro | ERR-024. |
| Resolver todos los códigos y sus jurisdicciones | ERR-025. |
| Comprobar tipo IC/MAT y modalidad Initial/Final | ERR-026. |
| Aplicar las reglas de tesorería al importar | ERR-027. |
| Importar contabilidad de 2025 sin permiso de pagos | ERR-028. |
| Corregir el perfil regional en una nueva previsualización | ERR-029. |
| Reimportar una corrección de importe del mismo pago | ERR-030. |

La suite existente dirigida a este bloque pasó **13 pruebas y 48 aserciones**. La auditoría adicional ejecutó **19 pruebas y 38 aserciones: 5 conformes y 14 no conformes**. Trece comprobaciones fallan por aserción y una termina con HTTP 403 inesperado; se agrupan en nueve incidencias nuevas, ERR-022 a ERR-030.

Reproducción en SQLite en memoria y almacenamiento local simulado:

```powershell
php vendor/bin/phpunit verification/import-audit/ImportacionFlujoAuditTest.php --colors=never --log-junit verification/import-audit/resultados.xml
```

Las pruebas se conservan fuera de `tests/` y sus fallos representan comportamiento pendiente de corrección. No se modificó código de aplicación, no se aplicaron importaciones a producción y los libros originales permanecen intactos.

## ERR-022 — La pantalla de importación no cumple el contrato de previsualización ni aplicación

- **Estado:** confirmado, pendiente. **Prioridad:** alta, bloquea el recorrido desde la interfaz.
- **Ubicación:** `resources/js/Pages/Accounting/Index.jsx:20`–`:34`, `:67`–`:79`; `app/Http/Controllers/AccountingImportController.php:14`–`:18`, `:25`–`:38` y `:44`–`:59`.

Se confirmaron tres incompatibilidades del mismo flujo:

1. El formulario de previsualización envía únicamente `file`; la API exige también `sheet`. Una petición con el mismo contenido que genera la pantalla vuelve con el error «El campo sheet es obligatorio». La pantalla tampoco proporciona un selector para ese dato.
2. `buildPreview()` devuelve filas con `source_row`, `normalized`, `raw` y `errors`. La pantalla espera `row`, `code`, `accounting_status`, `total_dr`, `total_wodr`, `can_apply` y un resumen `summary.valid/invalid`, que no se entregan. El botón de aplicación depende de `summary.valid`, por lo que queda deshabilitado con la respuesta actual.
3. El formulario de aplicación está inicializado como `{}` y no incorpora `import_id`, `row_ids` ni las resoluciones por fila. La petición que produce se rechaza por faltar los dos primeros campos, aunque la API tenga una importación válida en sesión.

Las pruebas HTTP reprodujeron los errores de validación en sesión, con redirección 302. El archivo del caso de previsualización supera la validación de tipo; el dato que falta es la hoja. Además, la interfaz ofrece CSV y XLSX mientras la ruta vigente acepta XLSX.

**Evidencia:** `test_payload_de_la_pantalla_permite_previsualizar`, `test_payload_de_aplicacion_de_la_pantalla_incluye_la_seleccion`, `test_contrato_preview_proporciona_los_campos_que_consume_la_pantalla`; archivos `payload-pantalla-preview.json`, `payload-pantalla-apply.json` y `contrato-preview.json` en `verification/import-audit/`.

**Corrección propuesta:** definir un contrato común, seleccionar hoja y perfil regional, presentar valores normalizados y errores, permitir resolver y seleccionar filas, y enviar sus identificadores y resoluciones. Alinear los formatos ofrecidos con los soportados. Verificar el recorrido completo hasta la aplicación confirmada.

## ERR-023 — Las filas posteriores a la número 100 no se pueden alcanzar desde la previsualización

- **Estado:** confirmado, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Http/Controllers/AccountingImportController.php:28`.

El parser y el almacenamiento admiten más de cien filas, pero `buildPreview()` devuelve siempre las primeras cien por ID. No tiene cursor, desplazamiento ni filtro para priorizar pendientes. Después de aplicar esas cien filas, vuelve a devolverlas y las siguientes continúan ocultas.

**Reproducción:** previsualizar 101 filas y marcar las primeras cien como aplicadas. La fila 101 permanece pendiente en la base de pruebas, pero no aparece en la respuesta y no existe parámetro para pedirla.

**Evidencia:** `test_preview_permite_alcanzar_filas_pendientes_despues_de_la_cien`.

**Corrección propuesta:** paginar por cursor y mostrar el total de filas pendientes, aplicadas y con errores; conservar su selección por identificador. El límite de cien por lote puede mantenerse sin hacer inaccesible el resto.

## ERR-024 — Los marcadores PENDIENTE del Excel se convierten en errores que no se pueden resolver

- **Estado:** confirmado, pendiente. **Prioridad:** alta para importar el archivo de referencia.
- **Ubicación:** `app/Services/Finance/ControlWorkbookParser.php:190`–`:205`; `app/Services/Finance/ControlImportService.php:121`; campos de resoluciones de `AccountingImportController.php:49`–`:59`.
- **Referencia Excel:** `2026!E3` y `2026!M3` contienen literalmente `PENDIENTE`.

El parser intenta convertir la fecha de envío Final y el cobro Initial pendiente como si fueran una fecha y un importe ya confirmados. Conserva el texto y añade `Fecha inválida en E` e `Importe inválido en M`. La aplicación exige que `errors` esté vacío, pero las resoluciones admitidas no permiten sustituir o reconocer esos marcadores en E y M ni reconstruir los errores.

**Reproducción:** fila válida de un expediente conocido con E y M en `PENDIENTE`. La previsualización devuelve los dos errores anteriores. Es un bloqueo independiente de los residuos de precisión de ERR-001: este caso no utiliza esos números problemáticos.

**Evidencia:** `test_pendiente_del_excel_es_desconocido_y_no_un_importe_invalido` y `verification/import-audit/marcadores-pendientes.json`.

**Corrección propuesta:** representar los marcadores conocidos de dato aún no disponible como valores desconocidos, conservando el texto original y evitando crear cobros. Para valores realmente inválidos, proporcionar un mecanismo explícito de resolución y revalidación. No reemplazar un pago pendiente por cero confirmado.

## ERR-025 — La identificación parcial descarta códigos sin resolver o fuera de la jurisdicción

- **Estado:** confirmado, pendiente. **Prioridad:** alta por atribución incorrecta de pagos.
- **Ubicación:** `app/Services/Finance/ControlImportService.php:70`–`:85`.
- **Referencia Excel:** los códigos asociados de una fila, por ejemplo `2025!B14`, requieren resolver la identidad completa.

Para una fila `case`, el servicio acepta que exista exactamente un expediente visible. No comprueba que todos los códigos de origen estén resueltos ni que todos correspondan a ese mismo expediente. Un código desconocido se pierde en la consulta; un segundo caso fuera de alcance desaparece al filtrar visibilidad. En ambos casos puede quedar un único destino visible que se considera válido.

**Reproducciones:**

- `IE-26-AUDIT & NO-RESUELTO`: se elige el primer caso y la fila queda sin errores de identidad.
- `IE-26-AUDIT & IE-26-OCULTO`: los códigos corresponden a dos casos diferentes y el actor tiene acceso al primero. La importación elige el caso visible, no registra errores y **crea en él un cobro de 20** descartando el otro expediente del origen.

**Evidencia:** `test_codigo_desconocido_no_se_descarta_al_identificar_un_caso`, `test_un_codigo_fuera_de_alcance_no_se_atribuye_al_otro_caso_visible` y `verification/import-audit/identidad-parcial.json`.

**Corrección propuesta:** resolver todos los códigos antes de decidir el destino. Una fila de expediente exige que sus aliases identifiquen al mismo caso y que esté autorizado. Una fila de grupo puede incluir varios casos, pero todos sus códigos y miembros deben estar resueltos y autorizados. No inferir el destino descartando la parte desconocida u oculta.

## ERR-026 — El tipo IC/MAT y la modalidad de facturación del origen no se contrastan con el destino

- **Estado:** confirmado, pendiente. **Prioridad:** alta para la coherencia financiera de la importación.
- **Ubicación:** `app/Services/Finance/ControlWorkbookParser.php:187`–`:188`; `app/Services/Finance/ControlImportService.php:70`–`:85` y aplicación de filas `case`.
- **Referencia Excel:** columna C de tipo y anotación `2025!D16 = 27/05/2025 (NO INITIAL SOLO FINAL)`.

El parser obtiene `invoice_type` y reconoce `billing_mode = final_only`, pero la previsualización no los compara con el expediente identificado por código. La aplicación tampoco traslada ni resuelve esa diferencia; conserva el tipo y modalidad anteriores del caso.

**Reproducción:** un destino IC estándar; previsualizar una fila que declara MAT y otra que declara IC con `NO INITIAL SOLO FINAL`. Ambas quedan sin errores, aunque sus configuraciones contradicen el destino. La segunda puede quedar aplicada sin que el caso deje de exigir Initial al emitir Final estándar.

**Evidencia:** `test_tipo_y_modalidad_incompatibles_exigen_resolucion`.

**Corrección propuesta:** detectar discrepancias y exigir un mapeo explícito antes de aplicar. No cambiar automáticamente el tipo o modalidad de un caso con documentos emitidos. Conservar la configuración resuelta y su procedencia para que importación y emisión utilicen el mismo expediente financiero.

## ERR-027 — La importación omite restricciones y actualizaciones de crédito del servicio de tesorería

- **Estado:** confirmado, pendiente. **Prioridad:** alta.
- **Ubicación:** `app/Services/Finance/ControlImportService.php:174`–`:195`, `:224`, `:299` y `:351`; reglas correspondientes en `app/Services/Finance/TreasuryService.php`.

El importador crea `TreasuryPayment` directamente. Cuando el estado del archivo coincide con el que ya tiene el caso, no pasa por las comprobaciones de transición. Tampoco ejecuta las reglas de registro de pagos de `TreasuryService`.

**Reproducciones confirmadas:**

1. Un caso ya ANULADO y una fila con ese mismo estado: se acepta un nuevo cobro de 20. El servicio ordinario de tesorería rechaza movimientos nuevos sobre ese estado.
2. Un caso CERRADO y un actor sin `billing_cases.adjust_closed`: se acepta un cobro de 20. La ruta ordinaria exige permiso de ajuste y motivo para modificar un caso cerrado.
3. Caso CERRADO con cobro previo 633, Final 583 y crédito 50. Con un actor que sí posee permiso de ajuste, importar otros 20 deja cobros 653 y **crédito 50**, aunque el excedente real ha subido a 70. El registro ordinario de tesorería actualiza ese crédito al recibir un nuevo cobro en el caso cerrado.

**Evidencia:** `test_no_se_importa_un_cobro_nuevo_a_un_expediente_anulado`, `test_caso_cerrado_exige_permiso_de_ajuste_tambien_al_importar`, `test_cobro_importado_en_caso_cerrado_actualiza_el_excedente` y `verification/import-audit/credito-caso-cerrado.json`.

**Corrección propuesta:** compartir el servicio de dominio y sus validaciones con el registro ordinario, incorporando la procedencia y claves de idempotencia de la importación. Si se necesita recuperar movimientos históricos de cerrados o anulados, usar una excepción explícita con permiso, motivo y conciliación de crédito, sin omitir esas reglas por la vía de importación.

## ERR-028 — Importar contabilidad de 2025 exige permiso de pagos aunque no se vaya a registrar caja

- **Estado:** confirmado, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Services/Finance/ControlImportService.php:174` y rama contable de 2025 en `:240`–`:270`; autorización de rutas `accounting-import.*`.

Las rutas permiten importar con `accounting.manage`. Sin embargo, toda fila `case` exige primero `payments.manage`, antes de comprobar si contiene un cobro. En 2025 el gasto contable V se procesa dentro de esa misma rama. Un contador autorizado a registrar revisiones no puede importar ese dato sin recibir además permiso de tesorería.

**Reproducción:** fila 2025 de un caso visible, con V = 580 y sin M ni otros pagos. El actor conserva `accounting.manage` y carece de `payments.manage`. La aplicación termina en **HTTP 403**, antes de crear la revisión contable, aunque no se solicitó movimiento de dinero.

**Evidencia:** `test_revisor_contable_puede_importar_contabilidad_2025_sin_crear_pagos`.

**Corrección propuesta:** autorizar por los efectos de la fila: revisión contable, cambios de metadatos y movimientos de caja deben exigir sus permisos correspondientes. Permitir el recorrido contable sin otorgar acceso de pagos cuando no se crea ni modifica caja.

## ERR-029 — Cambiar el perfil regional no actualiza una previsualización ya existente

- **Estado:** confirmado, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Services/Finance/ControlImportService.php:33`–`:52`; identidad por archivo, hoja, `control-v1` y actor.

El archivo se vuelve a parsear con el nuevo perfil, pero si ya existe una importación con el mismo hash, hoja y actor, el servicio devuelve sus filas anteriores sin reemplazar valores ni errores. El perfil regional no forma parte de esa identidad ni queda registrado para comparar interpretaciones.

**Reproducción:** previsualizar un monto textual `1,50` sin perfil regional: queda `Importe inválido en T`. Repetir con `es_EC`: el parser produce correctamente **1.50 sin errores**, pero el servicio devuelve la misma importación con **`1,50` y el error anterior**. El usuario no puede corregir la interpretación volviendo a previsualizar con el perfil adecuado.

**Evidencia:** `test_cambiar_perfil_regional_reconstruye_los_errores_del_preview` y `verification/import-audit/perfil-regional.json`.

**Corrección propuesta:** versionar la interpretación, incluyendo perfil y mapeo, o reconstruir de forma controlada las filas no aplicadas. Preservar las filas ya aplicadas y su interpretación histórica; no reinterpretar importes que ya generaron movimientos.

## ERR-030 — Un archivo corregido puede registrar otra vez el mismo pago con un importe diferente

- **Estado:** confirmado, pendiente. **Prioridad:** alta por duplicación de ingresos.
- **Ubicación:** `app/Services/Finance/ControlImportService.php:286`–`:307`; comprobaciones equivalentes de duplicación para los demás tramos.

La clave de origen depende del hash completo del archivo. Al corregir el libro cambia esa clave. La comprobación adicional de duplicados exige coincidencia simultánea de referencia, importe y fecha. Si se corrige el importe, ya no detecta que puede tratarse de la misma operación bancaria y crea otro cobro.

**Reproducción:** importar un cobro de 20 con referencia `IMP-3`, fecha 2026-10-20 y etapa Initial. Previsualizar una versión corregida de la misma fila por 30, manteniendo referencia, fecha, caso, tramo y etapa. La segunda aplicación crea un movimiento adicional de 30; quedan **dos pagos por 20 y 30, total 50**, sin solicitar una resolución de la corrección.

Repetir exactamente el mismo archivo sí es idempotente, como comprueba el recorrido básico. El fallo aparece al corregir el origen de un pago ya aplicado.

**Evidencia:** `test_archivo_corregido_no_suma_otra_vez_el_mismo_pago_con_importe_distinto` y `verification/import-audit/pago-en-archivo-corregido.json`.

**Corrección propuesta:** contrastar la identidad estable de la operación financiera además de la identidad del archivo. Ante una referencia ya aplicada con importe diferente, exigir resolución y un ajuste trazable o reversión; no sumar automáticamente el nuevo importe ni sobrescribir el movimiento histórico.

### Límites del cuarto bloque

Se probaron servicios, contratos de presentación y peticiones HTTP con base y almacenamiento de pruebas. Se volvió a comprobar el error de lectura del archivo original, pero no se aplicó contra una base real ni se alteró para eludir ERR-001. Las incidencias posteriores se reprodujeron con fixtures controlados. No se probó concurrencia real en MySQL ni se hizo una revisión visual completa de la interfaz.

Este bloque suma nueve incidencias nuevas: **30 incidencias confirmadas y pendientes en total**. El siguiente módulo pendiente es reportes y sus agregados.

## Quinto módulo revisado: reportes, agregados y alertas del control financiero

Fecha: 2026-10-28 (America/La_Paz).

La navegación comenzó en los nodos de `FinancialReportQuery`, `ReportController`, `ReportFilterRequest`, `Reports/Index` y `ControlQuery`. Se siguieron los consumidores de los agregados, incluyendo `AlertQuery` y `DashboardQuery`, y las dependencias de permisos, pagos y crédito. Se conservaron **22 archivos principales, 192 nodos y 280 enlaces internos** en `verification/reports-audit/navegacion-graphify.json`; todos constan en el manifiesto y sus tiempos de modificación coinciden con el índice.

Para verificar la descarga se consultaron también los manejadores instalados de Inertia, derivados de las importaciones de la pantalla y su middleware. `@inertiajs/react` y `@inertiajs/core` son versión 2.3.26. Los archivos concretos y sus hashes están en la evidencia de navegación; no se recorrió el resto del proyecto ni se actualizaron dependencias.

Se reutilizó la extracción verificada de doce celdas del Excel de control, guardada en `verification/reports-audit/celdas-control.json`. En particular, `2025!U3 = S3-T3` representa ingreso menos gasto y `2025!W3 = T3-V3` representa gasto registrado menos contabilidad. Las columnas AF y AH de 2026 distinguen esas dos magnitudes. No se modificó ni recalculó el libro original.

### Resultados por recorrido

| Recorrido | Resultado comprobado |
| --- | --- |
| Consolidar IC y MAT | Conforme: se suma Final una vez por expediente; Initial y los aliases no duplican el importe económico. |
| Desglose por fondo | Conforme: dos Final de 100 con DR 80 y WODR 20 producen total 200, DR 160 y WODR 40. |
| Resumen global y exportación con varias páginas | Conforme: 26 expedientes producen 2600 en el resumen y 26 filas únicas en el CSV, aunque el detalle de la primera página contiene 25. |
| Base de caja por año | Conforme: se excluye el cobro de otro año, se resta la devolución y el tramo EIA a FIEA no duplica el ingreso del capítulo a EIA. |
| Final faltante | Conforme: gasto y diferencia globales quedan pendientes; se conserva el subtotal operativo conocido. |
| Alcance básico por usuario | Conforme: sin una revisión conjunta, reporte y CSV excluyen los expedientes ajenos y sus cobros. |
| Permiso y textos de proyecto en CSV | Conforme: se exige `reports.view` y se neutralizan textos de proyecto con inicio de fórmula. |
| Descargar desde el enlace de la pantalla | ERR-031. La petición directa sin navegación Inertia sí entrega el CSV. |
| Crédito compartido en filas de proyecto y CSV | ERR-032. |
| Diferencia mostrada frente a diferencia exportada | ERR-033. |
| Alerta de un cobro parcial fuera de la primera página | ERR-034. |
| Alerta de una obligación vencida sin ningún abono | ERR-035. |
| Revisión conjunta fuera del alcance del usuario en reportes | Propagación de ERR-019; se conserva el mismo identificador. |

Las pruebas existentes dirigidas a este bloque pasaron **8 pruebas y 45 aserciones**. La auditoría adicional ejecutó **13 pruebas y 50 aserciones: 6 conformes y 7 no conformes**. Seis comprobaciones no conformes se agrupan en cinco incidencias nuevas; la séptima reproduce la propagación de ERR-019.

Reproducción en SQLite en memoria:

```powershell
php vendor/bin/phpunit verification/reports-audit/ReportesFlujoAuditTest.php --colors=never --log-junit verification/reports-audit/resultados.xml
```

La batería está fuera de `tests/`, preserva los fallos de auditoría y usa datos artificiales. No se modificó código de aplicación ni información financiera real.

## ERR-031 — El enlace CSV usa navegación Inertia y no entrega la descarga

- **Estado:** confirmado, pendiente. **Prioridad:** alta para utilizar la exportación desde la pantalla.
- **Ubicación:** `resources/js/Pages/Reports/Index.jsx:80`; `app/Http/Controllers/ReportController.php:26`; manejo de respuestas vacías del middleware instalado de Inertia.

La pantalla utiliza `<Link>` de Inertia para la ruta de exportación. Ese componente intercepta el clic y realiza una visita Inertia. El controlador devuelve una respuesta CSV por streaming, cuyo contenido no está disponible mediante `getContent()` antes de transmitirlo. En el middleware instalado, la respuesta se trata como vacía y se convierte en una redirección al origen.

**Reproducción HTTP:** realizar GET de exportación con las cabeceras que genera una navegación Inertia y la versión de recursos correcta. Se obtiene **HTTP 302, `text/html` y sin `Content-Disposition` de archivo**. La misma ruta sin esas cabeceras entrega HTTP 200 y el contenido CSV, como verifican los casos conformes.

**Evidencia:** `test_peticion_del_link_csv_es_compatible_con_navegacion_inertia` y `verification/reports-audit/csv-navegacion-inertia.json`. Se contrastó el evento de clic del cliente instalado y el manejo de `StreamedResponse` en el middleware; no se simuló una descarga visual en un navegador.

**Corrección propuesta:** utilizar un enlace nativo para descargar el archivo, conservando los filtros aplicados, o implementar un mecanismo de descarga explícito compatible con la navegación. Comprobar el archivo recibido desde el botón de la pantalla, no únicamente la ruta directa.

## ERR-032 — El crédito compartido se repite en las filas sin identificarlo como medida no aditiva

- **Estado:** confirmado, pendiente. **Prioridad:** alta para interpretar y sumar correctamente los reportes.
- **Ubicación:** `app/Services/Finance/ControlQuery.php:69`–`:71`, `:91`, `:184`–`:185`; `app/Http/Controllers/ReportController.php:38`; `resources/js/Pages/Reports/Index.jsx:126` y `:144`.

El crédito pertenece a una cuenta del equipo. El resumen global lo deduplica por cuenta, pero cada fila CSV vuelve a publicar el saldo completo bajo `credit_confirmed`. No incluye el identificador de cuenta ni señala en la columna que se trata de un saldo compartido que no puede sumarse por expediente. En la tabla por proyecto también se repite ese saldo bajo la etiqueta genérica «Crédito» cuando el mismo equipo tiene varios proyectos.

**Reproducciones:**

- Cuenta con crédito real 50 y dos expedientes del mismo equipo: el resumen muestra 50; el CSV contiene **50 en cada fila**, cuya suma es 100.
- La misma cuenta y dos proyectos del equipo: el resumen sigue en 50, pero las dos filas por proyecto muestran **50 y 50** sin reparto ni indicación de saldo compartido.

El saldo en la base de datos no se duplica. El fallo está en representar una medida compartida como si fuera un importe atribuible a cada fila, lo que impide sumar el CSV o reconciliar la tabla con el resumen sin conocer una regla que no se comunica en esa salida.

**Evidencia:** `test_csv_no_multiplica_credito_compartido_entre_expedientes`, `test_credito_por_proyecto_no_se_presenta_como_importe_repartido`, `credito-csv.json` y `credito-proyectos.json` en `verification/reports-audit/`.

**Corrección propuesta:** identificar cuenta y equipo y etiquetar explícitamente el saldo compartido, o exportar el crédito una sola vez en su propia granularidad. Si se necesita crédito atribuible a un expediente o proyecto, calcularlo desde sus movimientos en lugar de repetir el saldo global. La salida debe explicar qué columnas pueden sumarse.

## ERR-033 — La columna difference del CSV representa otra diferencia distinta de la pantalla

- **Estado:** confirmado, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Services/Finance/ControlQuery.php:115` y `:90`; `app/Http/Controllers/ReportController.php:29` y `:38`; tarjeta de diferencia de `resources/js/Pages/Reports/Index.jsx`.
- **Referencia Excel:** `2025!U` frente a `W`, equivalentes a las magnitudes diferenciadas por AF y AH en 2026.

La pantalla muestra «Diferencia» como **ingreso real menos gasto Final**. El CSV incluye una columna denominada `difference`, pero toma la diferencia **gasto registrado menos contabilidad**. No exporta una columna explícita para el saldo de caja que muestra la pantalla, aunque `case_cash_surplus` ya está disponible en la consulta.

**Reproducción:** ingreso 633 y Final 583, sin contabilidad confirmada. La pantalla y el saldo de caja del expediente valen **50**. La columna `difference` del CSV queda **vacía**, porque se refiere a contabilidad pendiente. Cuando exista un dato contable, esa misma columna seguirá expresando otra magnitud, no el saldo financiero de 50.

El vacío del gasto contable por fila también puede verse afectado por ERR-015; no se cuenta ese problema otra vez. Esta incidencia es la selección y denominación de métricas diferentes bajo el mismo nombre entre el reporte y su exportación.

**Evidencia:** `test_csv_identifica_la_misma_diferencia_financiera_que_el_reporte` y `verification/reports-audit/diferencias-reporte-csv.json`.

**Corrección propuesta:** exportar ambas diferencias con nombres inequívocos, por ejemplo ingreso menos Final y registrado menos contabilidad. Mantener pendiente la segunda cuando falta contabilidad, conservando la primera si sus dos importes ya están confirmados.

## ERR-034 — Las alertas de control financiero solo revisan la primera página de expedientes

- **Estado:** confirmado, pendiente. **Prioridad:** media para el seguimiento de cobros y descuadres.
- **Ubicación:** `app/Queries/AlertQuery.php:199`–`:203`.

`controlAlerts()` pide la primera página de `ControlQuery` y filtra sus 25 filas. No recorre el cursor ni consulta directamente los casos que requieren atención. Un caso posterior puede cumplir las condiciones de alerta y quedar fuera por su ID, aunque existan pocos avisos financieros relevantes.

**Reproducción:** 25 expedientes con cobertura completa de EIA y expectativa FIEA cero, seguidos por un expediente 26 con cobro 50 frente a Final 100. El expediente 26 tiene estado EIA `PENDIENTE`, que cumple el criterio del propio método, pero **no se genera su alerta específica de control financiero**.

Pueden existir avisos contables legados diferentes; no sustituyen el seguimiento del cobro parcial. El caso reproducido queda por debajo del límite general de 150 alertas, por lo que ese límite no explica la omisión.

**Evidencia:** `test_alerta_de_cobro_parcial_no_se_pierde_despues_de_25_casos` y `verification/reports-audit/alertas-segunda-pagina.json`.

**Corrección propuesta:** obtener las incidencias financieras de todo el alcance autorizado antes de aplicar la prioridad y el límite de resultados. No usar la página de visualización del control como universo completo de alertas.

## ERR-035 — Una obligación vencida sin ningún pago queda fuera de la alerta de control

- **Estado:** confirmado, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Queries/AlertQuery.php:200`–`:203`; estados producidos por `ControlQuery::legStatus()`.

El criterio de alerta incluye `PENDIENTE`, pero excluye `NO PAGADO`. Por tanto el cobro parcial puede generar una alerta mientras una obligación sin ningún abono no la genera, incluso vencida, si el otro tramo está cubierto y no hay una diferencia contable que active el primer criterio.

**Reproducción:** Initial aprobado de 150 con vencimiento anterior al día de la prueba, sin Final ni cobros. La expectativa FIEA está confirmada en cero. El control devuelve EIA **NO PAGADO**, esperado 150, recibido cero; FIEA **PAGADO**. El conjunto de alertas específicas de control financiero queda vacío.

**Evidencia:** `test_alerta_de_control_incluye_una_obligacion_sin_ningun_pago` y `verification/reports-audit/alerta-sin-pago.json`.

**Corrección propuesta:** incluir las obligaciones sin cobertura cuando requieren seguimiento, distinguiendo cero esperado de importe esperado positivo. Incorporar vencimiento y estado del expediente a la prioridad; no excluir el cobro por el solo hecho de que todavía no tenga un primer abono.

### Límites del quinto bloque

Se verificaron datos, permisos, exportación por streaming y respuestas HTTP en SQLite en memoria. No se realizó una revisión visual completa ni una descarga interactiva en navegador. La compatibilidad de la descarga se contrastó con las dependencias instaladas y la petición que produce el componente actual. No se probó concurrencia real en MySQL ni se modificaron archivos de aplicación o los Excel originales.

Este bloque suma cinco incidencias nuevas y confirma una propagación de ERR-019: **35 incidencias confirmadas y pendientes en total**. Queda pendiente revisar los recorridos de gastos reales y sus comprobantes, además de los módulos operativos que entregan y respaldan esos datos.

### Sexto bloque — Gastos reales y comprobantes

Revisión del 2026-10-28. Se siguió el árbol existente de Graphify desde `ActualExpenseController`, `ExpenseMutationService` y `ReceiptController` hacia sus solicitudes, servicios, modelos y pantallas. Para contrastar el recorrido nuevo se consultaron sus conexiones con `BudgetService`, `BudgetLineVersion` y `BudgetCell`: **33 archivos seleccionados, 235 nodos y 393 enlaces internos** del árbol general de 2.652 nodos y 6.947 enlaces. Los tiempos de modificación de los 33 archivos coinciden con el manifiesto. No se leyó todo el proyecto.

La referencia documental de recibos se verificó en `MASTER TEMPLATE!CP24`, `CP28`, `CP50`, `CP56` y `CP57`; la factura IC Final los enlaza en `M18`, `M22`, `M41`, `M48` y `M49`. La plantilla MAT Final también ofrece `N18`, «RECEIPT #». El libro contiene números de recibo y textos como «Flat Fee»; **no se interpreta que exija adjuntar archivos digitales**. Las incidencias sobre archivos verifican el flujo que la propia aplicación ofrece. Los casos de alcance, 26 registros y comparación 100/160 son escenarios sintéticos, identificados como tales.

| Flujo revisado | Resultado |
| --- | --- |
| Gasto antiguo: cantidad por costo, fondo calculado en servidor y separación del estimado | Conforme: 16 × 10 = 160; estimado 100 conservado; fondo DR no alterado por el cliente. |
| Versión obsoleta al crear gastos o subir archivos | Conforme: HTTP 409 sin nuevos registros ni objetos. |
| Subida y descarga autorizadas, metadatos privados y eliminación diferida | Conforme: archivo privado, auditoría, incremento de versión y evento de borrado en outbox. |
| Listado, descarga y modificación fuera del alcance del usuario | Conforme: no se lista el registro; descarga 404 y modificación 403. |
| Bloqueo con Final aprobado o expediente cerrado/anulado | Conforme en los escenarios ejecutados. |
| Primera captura Final después del Initial, sobre una línea ya existente | Conforme en servicio: costo 10, cantidad diaria 16 y texto de recibo conservados. |
| Adjuntar un archivo a una línea Final nueva IC o MAT | No conforme: ERR-036. |
| Subir, corregir o eliminar respaldo con Initial aprobado y Final abierto | No conforme: ERR-037. |
| Indicadores de estimado, real, variación y monto comprobado | No conforme: ERR-038. |
| Seleccionar fases, estimados o gastos fuera de los primeros 25 | No conforme: ERR-039. |
| Segundo guardado de una celda diaria en el SQLite configurado | No conforme: ERR-040; alcance confirmado en SQLite. |

**Pruebas existentes:** `ActualExpenseManagementTest`, `ReceiptManagementTest` y `PrivateDocumentSecurityTest`: **18 aprobadas, 50 aserciones**.

**Pruebas adicionales de auditoría:** `verification/expenses-audit/GastosComprobantesFlujoAuditTest.php`: **16 pruebas, 59 aserciones; 7 conformes y 9 fallos de contrato**, agrupados en cinco incidencias nuevas. Los fallos son reproducciones deliberadas y quedan fuera de la suite normal; no son correcciones implementadas ni errores de preparación de datos.

```powershell
php artisan test --filter='ActualExpenseManagementTest|ReceiptManagementTest|PrivateDocumentSecurityTest' --colors=never
php vendor/bin/phpunit verification/expenses-audit/GastosComprobantesFlujoAuditTest.php --colors=never --log-junit verification/expenses-audit/resultados.xml
```

**Evidencia:** `verification/expenses-audit/navegacion-graphify.json`, `celdas-control.json`, `resultados.xml`, `resumen.json` y los escenarios JSON citados abajo. Se verificaron los hashes de los archivos de aplicación consultados y de ambos Excel originales.

## ERR-036 — Las líneas nuevas de la matriz Final no tienen acceso al flujo de archivos de respaldo

- **Estado:** confirmado, pendiente. **Prioridad:** alta para respaldar la ejecución desde la matriz nueva.
- **Ubicación:** `app/Http/Controllers/ReceiptController.php:34`–`:37`; `app/Http/Requests/StoreReceiptRequest.php:17` y `:29`; `app/Services/Receipts/StoreReceipt.php:26` y `:45`; `app/Services/Finance/BudgetService.php:112`; `app/Services/Finance/ExpenseMutationService.php:30`.

El formulario de comprobantes solo permite elegir un `ActualExpense`; la solicitud y la tabla de recibos requieren ese padre. Una línea nueva creada y capturada mediante `BudgetService` pertenece a `BudgetLine` y `BudgetLineVersion`, sin generar un gasto antiguo ni ofrecer un vínculo equivalente para el archivo. El campo `legacy_actual_expense_id` puede identificar líneas migradas, pero queda vacío en las nuevas.

**Reproducción en IC y MAT, por separado:** crear un expediente, agregar una línea por el servicio real y capturar Final por 160 con número de recibo. Al consultar `/recibos`, los gastos disponibles están vacíos. Intentar crear el padre antiguo devuelve **409**, porque la fase ya utiliza la matriz; intentar subir el archivo identificando la línea de matriz devuelve **403**, porque falta el `actual_expense_id` exigido por la autorización. No se crea ningún recibo.

El número textual sí queda guardado en la matriz. El fallo afecta al archivo digital ofrecido por el módulo, no a la multiplicación del importe. Es independiente del contrato de pantalla de ERR-002, la inicialización diaria de ERR-003 y la impresión del número en ERR-009: se aisló con servicios y peticiones directas, sin depender de esas pantallas.

**Evidencia:** `test_linea_ic_nueva_permite_llegar_a_la_subida_del_archivo`, `test_linea_mat_nueva_permite_llegar_a_la_subida_del_archivo`, `puente-matriz-IC.json` y `puente-matriz-MAT.json`.

**Corrección propuesta:** permitir que el comprobante se vincule a la línea o versión Final y, cuando corresponda, a su celda diaria, con alcance y versión del expediente. Como alternativa transitoria, proporcionar explícitamente un padre compatible y mantener la correspondencia. La creación de un gasto financiero duplicado no debe convertirse en requisito para adjuntar el respaldo.

## ERR-037 — Aprobar el Initial congela también los comprobantes del Final todavía abierto

- **Estado:** confirmado, pendiente. **Prioridad:** alta.
- **Ubicación:** `app/Services/Receipts/StoreReceipt.php:39`–`:40`; `app/Actions/Receipts/UpdateReceipt.php:41`–`:42`.

Los dos servicios bloquean las operaciones si existe **cualquier** factura `approved`, `sent` o `paid` en la fase. No comprueban etapa, expediente ni si el Final correspondiente está aprobado. En cambio, `BudgetService::update()` sí distingue el puntero de la etapa y permite capturar Final después de aprobar Initial.

**Reproducción:** conservar un gasto antiguo accesible y su fase abierta, crear un expediente con Initial aprobado y sin Final vigente. La subida devuelve **409**, con cero archivos y cero recibos nuevos. Con un recibo existente anterior al Initial, tanto corregir sus metadatos como eliminarlo devuelve **409**; su monto queda en 160. La versión enviada es vigente y no existe cierre ni anulación que explique el bloqueo.

Se utilizó un padre antiguo existente para aislar este error del puente ausente de ERR-036. El caso conforme verifica que aprobar el Final sí mantiene congelados los comprobantes.

**Evidencia:** `test_initial_aprobado_permite_subir_respaldo_del_final_abierto`, `test_initial_aprobado_permite_corregir_respaldo_del_final_abierto`, `initial-bloquea-subida.json` e `initial-bloquea-correccion.json`.

**Corrección propuesta:** vincular la evidencia a su expediente y etapa. Conservar la inmutabilidad del Initial y del Final aprobado sin impedir respaldar un Final abierto. Las correcciones posteriores a la aprobación deben seguir el mecanismo de revisión previsto, con auditoría.

## ERR-038 — Los indicadores de gastos y comprobantes esperan resúmenes que los controladores no entregan

- **Estado:** confirmado, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Http/Controllers/ActualExpenseController.php:27`–`:76`; `app/Http/Controllers/ReceiptController.php:33`–`:49`; `resources/js/Pages/ActualExpenses/Index.jsx:75`–`:76` y `:192`–`:194`; `resources/js/Pages/Receipts/Index.jsx:57`–`:58` y `:152`.

La pantalla de gastos calcula sus tarjetas desde `summary.estimated`, `summary.real` y `summary.variance`; la de comprobantes espera `summary.amount`. Si el resumen no coincide con la selección o falta, los valores quedan en `null`. Ninguno de los controladores construye esa propiedad ni un agregado alternativo para estas tarjetas.

**Reproducción HTTP:** fase autorizada con estimado 100 y gasto real 160. La respuesta diferida proporciona ambas líneas, pero el resumen solicitado resulta **null**, por lo que no aporta los indicadores 100, 160 y variación 60. Con un comprobante de 160, la pantalla de recibos recibe su fila, pero el resumen de «Monto comprobado» también resulta **null**.

No se trata de datos pendientes en estos escenarios: los registros ya están completos. Tampoco se debe corregir sumando únicamente las 25 filas visibles, porque entonces la tarjeta cambiaría con la página y dejaría de representar el conjunto seleccionado.

**Evidencia:** `test_pantalla_gastos_entrega_los_totales_que_muestra`, `test_pantalla_recibos_entrega_el_monto_comprobado`, `totales-gastos.json` y `totales-recibos.json`. Se verificó el contrato HTTP y su consumo en JSX; no se realizó una captura visual de las tarjetas.

**Corrección propuesta:** definir la selección en la consulta, calcular los agregados completos dentro del alcance autorizado y devolver el resumen que consume la pantalla. Actualizarlo al cambiar de fase o gasto, diferenciando un cero confirmado de datos pendientes.

## ERR-039 — Los selectores de gastos y comprobantes excluyen registros después del límite de 25

- **Estado:** confirmado, pendiente. **Prioridad:** media para completar registros anteriores o proyectos con muchas líneas.
- **Ubicación:** `app/Http/Controllers/ActualExpenseController.php:55`–`:62`; `app/Http/Controllers/ReceiptController.php:34`–`:37`; selectores de `resources/js/Pages/ActualExpenses/Index.jsx:214` y `:226`, y `resources/js/Pages/Receipts/Index.jsx:172`.

Las listas principales usan cursor, pero los padres ofrecidos por los formularios se obtienen con `limit(25)->get()`, sin cursor ni búsqueda remota. La paginación de gastos o recibos no amplía esas opciones. El filtro de estimados se aplica en el cliente después del límite global, por lo que una fase puede aparecer sin líneas estimadas aunque sí las tenga.

**Reproducciones:**

- Crear 26 fases autorizadas y 26 estimados; la fase antigua y el estimado cuyo nombre queda después de los primeros 25 no llegan a los selectores. Las dos tablas contienen los registros, pero la respuesta solo incluye 25 opciones de cada tipo.
- Crear 26 gastos autorizados, con el objetivo reportado antes que los otros 25. `/recibos` solo ofrece los 25 recientes; no permite elegir el gasto objetivo para cargarle un comprobante nuevo desde ese formulario.

Se confirma una limitación de acceso desde la interfaz, no una pérdida del registro en la base de datos. No se duplica ERR-023 ni ERR-034: esos casos pertenecen a la previsualización de importaciones y a la selección de alertas; aquí se trata del contrato de opciones de captura.

**Evidencia:** `test_selectores_de_gastos_permiten_llegar_a_la_fase_y_estimado_26`, `test_selector_de_recibos_permite_elegir_el_gasto_26`, `selectores-gastos.json` y `selector-recibos.json`.

**Corrección propuesta:** ofrecer búsqueda o paginación de opciones con alcance autorizado, filtrar los estimados por la fase elegida antes de limitar y conservar el elemento actualmente seleccionado. El cursor de una tabla distinta no sustituye el acceso a las opciones del formulario.

**Ampliación del 2026-10-28 — proyectos y viajes:** el mismo patrón se reproduce en los selectores de países y comunidades de `ProjectController.php:36`–`:42`, y de proyectos y equipos de `TripPhaseController.php:65`–`:79`. Con 26 registros autorizados de cada tipo, los cuatro objetivos que quedan después del límite desaparecen de las opciones sin búsqueda ni cursor. Evidencia: `verification/projects-trips-audit/propagacion-ERR-039.json`. Es una propagación de esta incidencia; no se suma como error nuevo.

**Ampliación del 2026-10-28 — capítulos y contactos:** los tipos de capítulo y universidades de `ChapterController.php:39`–`:44`, así como los capítulos y equipos de `ContactController.php:44`–`:52`, repiten el límite de 25 opciones sin acceso a las posteriores. Se reprodujo con 26 registros autorizados de cada tipo. Evidencia: `verification/teams-contacts-audit/propagacion-ERR-039.json`. No se suma una incidencia nueva.

## ERR-040 — El segundo guardado de una celda diaria falla en el SQLite configurado

- **Estado:** confirmado en SQLite, pendiente. **Prioridad:** alta en el entorno local configurado; no verificado en MySQL.
- **Ubicación:** `app/Services/Finance/BudgetService.php:69`; `app/Models/BudgetCell.php:15`.
- **Entorno:** `.env` declara `DB_CONNECTION=sqlite`; las pruebas usan SQLite en memoria. El ejemplo de configuración y el README indican MySQL, cuyo comportamiento no se presume idéntico.

La primera captura guarda `spent_on` como **`2025-07-10 00:00:00`** debido al cast de fecha del modelo. En el guardado siguiente, `updateOrCreate()` busca por el texto **`2025-07-10`**. SQLite compara esas representaciones como diferentes. Al no encontrar la fila, se intenta insertar; el cast vuelve a producir el mismo valor con hora y colisiona con la clave única de versión y día.

**Reproducción HTTP:** crear una línea IC y capturar Final con costo 10, cantidad 16 y recibo «FLAT FEE» para el 10 de julio. Reenviar la misma fecha con versión vigente, costo 12, cantidad 17 y recibo «CORREGIDO». PATCH devuelve **HTTP 500**, no un guardado ni un conflicto de versión. La llamada directa confirma `UniqueConstraintViolationException` y la restricción `budget_cells.budget_line_version_id, budget_cells.spent_on`.

La transacción revierte el segundo intento: quedan costo 10, cantidad 16, el recibo original y la misma versión de expediente. No se crean dos celdas, pero la corrección del gasto no puede guardarse. La prueba de primera captura después del Initial sí es conforme; esta incidencia exige guardar de nuevo el mismo día y se aisló sin depender de ERR-002.

**Evidencia:** `test_segundo_guardado_diario_actualiza_la_celda_en_sqlite` y `segundo-guardado-sqlite.json`, con entrada, HTTP, excepción, consultas y valores conservados.

**Corrección propuesta:** normalizar la persistencia y la búsqueda del día a una representación consistente en los motores admitidos, conservando su unicidad. Revisar las celdas existentes antes de normalizar datos y comprobar el ciclo de guardar y corregir, además de la primera inserción. Si SQLite no se admite para la aplicación, declararlo y evitar configurarlo como entorno operativo; eso no demuestra por sí mismo una corrección en MySQL.

**Ampliación del 2026-10-28 — comparación del rango del viaje:** la discrepancia de representaciones también afecta `TripPhaseMutationService.php:62`–`:64`. Una celda del último día se guarda como `2025-07-12 00:00:00`; al ampliar solamente el inicio del viaje del 10 al 9 de julio, manteniendo el final el día 12, SQLite la considera mayor que el límite textual `2025-07-12`. PATCH devuelve 422 aunque el gasto sigue dentro del viaje. Evidencia: `verification/projects-trips-audit/propagacion-ERR-040.json`. Se conserva el alcance específico de SQLite y no se suma una incidencia nueva.

### Límites del sexto bloque

Se verificaron servicios, respuestas HTTP, privacidad de descargas, alcance, versiones y contratos de datos con SQLite en memoria y almacenamiento local simulado. No se escribieron datos en la base operativa, no se realizó una revisión visual completa ni se ejecutó el consumidor de outbox para borrar objetos reales. No se probó concurrencia real ni almacenamiento remoto R2. ERR-040 se limita expresamente a SQLite, que está configurado en este entorno; no se extrapola a MySQL.

No se modificaron archivos de aplicación ni los Excel originales. Este bloque suma cinco incidencias nuevas: **40 incidencias confirmadas y pendientes en total**, una de ellas con alcance específico de SQLite. El siguiente módulo propuesto es la configuración operativa de proyectos y fases de viaje, desde su creación hasta la habilitación de la matriz y sus permisos.

### Séptimo bloque — Proyectos y fases de viaje

Revisión del 2026-10-28. Se navegó el árbol de Graphify desde `ProjectController`, `TripPhaseController`, `SaveProject` y `TripPhaseMutationService` hacia sus solicitudes, permisos, pantallas y conexiones financieras: **30 archivos seleccionados, 249 nodos y 458 enlaces internos**. Los tiempos de modificación coinciden con el manifiesto del árbol general de 2.652 nodos y 6.947 enlaces. No se leyó todo el proyecto.

Se contrastaron los campos de cabecera con `MASTER TEMPLATE!C8:C11` y las fechas `C13:D13`. Las fechas diarias Initial y Final enlazan el mismo viaje mediante `E21`, `U21`, `AX21` y `BN21`. El libro original contiene 8 voluntarios, 1 integrante de personal y fechas del 10 al 18 de julio de 2025. Las pruebas usan identidades y un viaje sintéticos para aislar permisos y cambios de estado. **El contenido del libro se trató como datos de referencia; no como instrucciones ni como definición de reglas de cancelación o asignación de técnicos.**

| Flujo revisado | Resultado |
| --- | --- |
| Crear proyecto, fase, expediente y capturar matriz mediante las API existentes | Conforme: la cabecera del snapshot conserva proyecto, participantes y fechas de la fase. |
| Duplicar un expediente del mismo tipo en una fase | Conforme: 409; IC y MAT pueden coexistir como tipos distintos. |
| Editar o eliminar una fase con versión obsoleta | Conforme: 409, sin modificarla. |
| Acortar fechas dejando cantidades no nulas fuera del viaje | Conforme en el escenario ejecutado: 422, sin recortar el viaje. |
| Cambiar proyecto o fechas con factura aprobada | Conforme: 409; snapshot conservado. |
| Usuario asignado: edición de su fase y traslado a padres ajenos | Conforme: edición autorizada; traslado ajeno rechazado con 404. |
| Permiso de proyectos con alcance parcial | Conforme: permite editar el proyecto expresamente asignado; no concede alta global. |
| Eliminar proyecto con fase o fase con expediente | Conforme: dependencia preservada. |
| Renombrar proyecto después de emitir | Conforme: cambia el catálogo sin reescribir el snapshot aprobado. |
| Abrir «Matriz diaria» después de crear una fase | No conforme: ERR-041. |
| Primera asignación de un técnico activo desde el selector | No conforme: ERR-042. |
| Validación de técnico inactivo o usuario sin rol elegible | No conforme: ERR-043. |
| Acortar fechas eliminando del viaje un día vacío | No conforme: ERR-044. |
| Cancelar viaje y continuar emitiendo o aprobando documentos | No conforme con el bloqueo de matriz aplicado por la propia aplicación: ERR-045. |
| Selectores operativos con más de 25 opciones | Propagación de ERR-039, sin nuevo conteo. |
| Ampliar inicio manteniendo un gasto en el último día, en SQLite | Propagación de ERR-040, sin nuevo conteo. |

**Pruebas existentes:** `ProjectManagementTest`, `TripPhaseManagementTest` y `JurisdictionSecurityTest`: **19 aprobadas, 47 aserciones**.

**Pruebas adicionales:** `verification/projects-trips-audit/ProyectosViajesFlujoAuditTest.php`: **18 pruebas, 68 aserciones; 9 conformes y 9 fallos de contrato**. Siete fallos reproducen cinco incidencias nuevas; los otros dos confirman las propagaciones indicadas. No hubo excepciones inesperadas de ejecución. La batería queda fuera de la suite normal y documenta los resultados actuales, sin corregirlos.

```powershell
php artisan test --filter='ProjectManagementTest|TripPhaseManagementTest|JurisdictionSecurityTest' --colors=never
php vendor/bin/phpunit verification/projects-trips-audit/ProyectosViajesFlujoAuditTest.php --colors=never --log-junit verification/projects-trips-audit/resultados.xml
```

**Evidencia:** `verification/projects-trips-audit/navegacion-graphify.json`, `celdas-control.json`, `resultados.xml`, `resumen.json` y los JSON de cada reproducción. En los resultados de servicios, 200 significa operación completada sin excepción; no implica una petición HTTP. Las altas y modificaciones de proyectos y fases sí se ejercitaron por HTTP.

## ERR-041 — El enlace de matriz de una fase nueva termina en 404 sin iniciar su expediente

- **Estado:** confirmado, pendiente. **Prioridad:** alta para comenzar el flujo financiero desde la interfaz.
- **Ubicación:** `resources/js/Pages/TripPhases/Index.jsx:356`; `app/Http/Controllers/BudgetController.php:35`–`:40`; `app/Services/Finance/TripPhaseMutationService.php:32`; ruta `billing-cases.store` en `routes/web.php:59`.

Crear una fase guarda únicamente `TripPhase`. Su fila ofrece «Matriz diaria», que abre IC/Initial, pero el controlador de presupuesto exige un `BillingCase` existente y llama a `firstOrFail()`. Las pantallas revisadas de viajes y presupuesto no ofrecen el paso de alta del expediente antes de ese acceso. El control contable tampoco proporciona ese alta.

**Reproducción HTTP:** crear una fase con un usuario global que dispone de permisos de viajes y presupuestos; el alta redirige sin errores. La fase queda con **cero expedientes**. Seguir el enlace exacto de su fila devuelve **404**. El mismo usuario puede crear el expediente mediante el POST existente, como verifica el recorrido conforme de la API, pero esa operación falta en este recorrido de interfaz.

Es anterior e independiente de ERR-002: aquí todavía no existe expediente y no se alcanza la respuesta que selecciona el componente de presupuesto incorrecto. Corregir el inicio del expediente no resuelve por sí solo aquel contrato de pantalla.

**Evidencia:** `test_fase_recien_creada_puede_iniciar_la_matriz_desde_su_enlace` e `inicio-matriz.json`.

**Corrección propuesta:** incorporar un paso visible para crear IC o MAT y elegir el modo de facturación, o presentar ese inicio al abrir una fase sin expediente. El GET no debería crear registros silenciosamente. Tras el alta, dirigir a una matriz válida, con los permisos y metadatos requeridos.

## ERR-042 — El selector excluye técnicos activos que todavía no tienen una fase asignada

- **Estado:** confirmado, pendiente. **Prioridad:** media para programar y delegar viajes.
- **Ubicación:** `app/Http/Controllers/TripPhaseController.php:80`–`:86`; `resources/js/Pages/TripPhases/Index.jsx:205`.

Además de exigir actividad y rol `tecnico` o `superadmin`, la consulta limita las opciones al propio actor y a usuarios ya asignados a sus fases visibles. Incluso para un gestor con alcance global, un técnico nuevo sin viajes queda fuera. La primera asignación depende así de que ya exista una asignación previa.

**Reproducción:** gestor global y un segundo usuario activo con rol exacto `tecnico`, sin fase asignada. Hay solo dos usuarios, por debajo del límite de 25. El selector devuelve **cero opciones**. Una petición directa de actualización asigna correctamente a ese candidato y redirige sin errores, lo que confirma que la exclusión proviene de la consulta de opciones.

No es otra manifestación del límite de ERR-039: falla con dos usuarios y depende de la asignación previa.

**Evidencia:** `test_tecnico_activo_nuevo_esta_disponible_para_primera_asignacion` y `tecnico-sin-primera-asignacion.json`.

**Corrección propuesta:** definir un conjunto de candidatos elegibles que permita la primera asignación, respetando la jurisdicción del gestor. Para alcance global, incluir técnicos activos elegibles aunque todavía no tengan viajes; para alcance parcial, utilizar una regla explícita de pertenencia o delegación, no la existencia circular de una fase anterior.

## ERR-043 — El backend acepta como técnico usuarios inactivos o sin el rol que exige la interfaz

- **Estado:** confirmado, pendiente. **Prioridad:** alta por su efecto en la asignación y el alcance derivado.
- **Ubicación:** `app/Http/Requests/StoreTripPhaseRequest.php:21`; herencia en `UpdateTripPhaseRequest`; `app/Services/Finance/TripPhaseMutationService.php:67`; `app/Services/Security/ScopeService.php:24`.

La solicitud solo comprueba que `assigned_technician_id` exista en `users`. No valida actividad ni elegibilidad, aunque la consulta de la interfaz sí exige usuario activo y roles concretos. El servicio conserva el valor y `ScopeService` utiliza esa asignación para conceder alcance sobre la fase.

**Reproducciones HTTP con un gestor autorizado:**

- Asignar un usuario con rol técnico pero **inactivo**: PATCH devuelve 302 sin errores y persiste su ID como responsable.
- Asignar un usuario activo con permiso de lectura de presupuestos, alcance global desactivado y un rol ajeno a `tecnico`/`superadmin`: PATCH también devuelve 302 sin errores. Antes no tenía alcance sobre la fase; después, `ScopeService::permits()` devuelve **true** por la asignación recién guardada.

No se afirma que el gestor carezca de permiso para asignar personal ni que la asignación otorgue nuevos permisos RBAC. El fallo es la discrepancia de elegibilidad entre interfaz y servidor, que además modifica el alcance disponible para los permisos que el candidato ya tenía.

**Evidencia:** `test_solicitud_rechaza_asignar_tecnico_inactivo`, `test_solicitud_rechaza_usuario_sin_rol_tecnico_antes_de_ampliar_alcance`, `asignacion-inactivo.json` y `asignacion-no-tecnico.json`.

**Corrección propuesta:** centralizar la elegibilidad de responsables y aplicarla tanto al selector como a la solicitud y al servicio dentro de la transacción. Comprobar actividad y la capacidad o rol técnico autorizado. Si se admiten otros roles, exponer y validar esa regla de forma consistente; conservar la posibilidad de dejar la asignación vacía cuando corresponde.

## ERR-044 — Recortar un día vacío deja una celda fuera de rango que impide emitir el Final

- **Estado:** confirmado, pendiente. **Prioridad:** alta para ajustar fechas sin inutilizar la matriz.
- **Ubicación:** `app/Services/Finance/TripPhaseMutationService.php:59`–`:67`; `app/Services/Finance/FinancialSnapshotBuilder.php:84`–`:85`.

El cambio de fechas solo bloquea celdas fuera de rango cuya cantidad sea distinta de cero. Permite recortar días vacíos, pero no elimina ni trata esas celdas. El constructor de snapshot, en cambio, exige que **todas** estén dentro del viaje, incluidas las de cantidad cero.

**Reproducción:** viaje del 10 al 12 de julio, con cantidad 16 el día 10 y una celda vacía de cantidad cero el día 12. El snapshot Final se construye correctamente antes del cambio. Cambiar el final del viaje al día 11 devuelve **302 sin errores** y guarda la fecha nueva. La celda cero del día 12 permanece; construir el snapshot Final ahora se rechaza con **422**.

No se descartó un gasto real: la celda excluida tiene cantidad cero y carece de número de recibo. Se escogió un gasto en el día 10 para aislar esta inconsistencia del problema de límite de fechas de SQLite documentado en ERR-040.

**Evidencia:** `test_acortar_dias_vacios_deja_final_valido_para_emitir` y `recorte-dia-vacio.json`.

**Corrección propuesta:** tratar de forma atómica las celdas afectadas al cambiar el calendario. Se pueden retirar las que estén completamente vacías, conservando la auditoría, o rechazar el cambio antes de guardarlo y ofrecer una resolución explícita. No eliminar automáticamente celdas con evidencia asociada por el solo hecho de tener cantidad cero. El viaje guardado y el constructor de documentos deben aplicar la misma regla.

## ERR-045 — Cancelar el viaje bloquea la matriz pero deja emitir y aprobar sus facturas

- **Estado:** confirmado, pendiente. **Prioridad:** alta para coherencia de estados y autorización de emisión.
- **Ubicación:** `app/Services/Finance/TripPhaseMutationService.php:67`; `app/Services/Finance/BudgetService.php:31`; `app/Services/Finance/InvoiceLifecycleService.php:25`, `:47`, `:107` y `:153`–`:174`.

La actualización de una fase permite guardar `status=cancelled` y conserva su expediente `ABIERTO`. El servicio de matriz rechaza cambios por ese estado de la fase, pero el ciclo de factura solo comprueba el estado del expediente y no la cancelación del viaje.

**Reproducciones aisladas:**

- Capturar un Final en un expediente `final_only`, cancelar la fase mediante PATCH y comprobar, antes de emitir, que editar la matriz ya se rechaza con 409. Después, crear el borrador y aprobarlo con otro usuario autorizado **se completa sin excepción**, dejando la factura en `approved` mientras la fase sigue `cancelled`.
- Crear un borrador antes de cancelar; cancelar la fase y aprobar ese borrador con un revisor diferente también **se completa**, sin una transición financiera adicional.

La creación y aprobación se ejercitaron directamente por sus servicios reales; los códigos 200 de sus JSON significan operación completada, no una respuesta HTTP observada. No se enviaron documentos ni mensajes externos.

Los Excel no especifican qué obligaciones deben liquidarse al cancelar. La inconsistencia confirmada es que la aplicación cierra la edición por cancelación, pero permite continuar una emisión ordinaria sin comprobar ni comunicar esa situación. Si se deben permitir liquidaciones por gastos ya incurridos, requieren una regla explícita y coherente con el estado financiero.

**Evidencia:** `test_cancelar_viaje_bloquea_nueva_emision_financiera`, `test_cancelar_viaje_bloquea_aprobar_borrador_previo`, `cancelado-emite.json` y `cancelado-aprueba-borrador.json`.

**Corrección propuesta:** definir la transición entre cancelación operativa y expediente financiero y comprobarla al crear, actualizar y aprobar documentos. Bloquear la emisión ordinaria del viaje cancelado o habilitar una liquidación explícita, con permisos y motivo, según la regla acordada. Preservar documentos y obligaciones existentes; no anular ni borrar movimientos financieros automáticamente.

### Límites del séptimo bloque

Se revisaron contratos HTTP de altas, edición, borrado y listas diferidas, y servicios financieros conectados a esos cambios. Las pruebas usan SQLite en memoria; no modificaron la base operativa. No se realizó una revisión visual completa, ni concurrencia real en MySQL, ni pruebas de entrega externa. Las observaciones de rangos de fecha debidas a representaciones de SQLite conservan el alcance de ERR-040 y no se atribuyen a MySQL.

No se modificaron archivos de aplicación ni los Excel originales. Este bloque suma **cinco incidencias nuevas y dos propagaciones sin duplicar: 45 incidencias confirmadas y pendientes en total**. El siguiente módulo propuesto es equipos, capítulos y contactos de facturación, que alimentan la identidad documental y los destinatarios del flujo financiero.

### Octavo bloque — Equipos, capítulos y contactos de facturación

Revisión del 2026-10-28. Se utilizó el árbol de Graphify para navegar desde `ChapterController`, `TeamController`, `ContactController` y `ContactAssignmentController` hacia sus acciones, solicitudes, pantallas y conexiones con la facturación: **34 archivos seleccionados, 291 nodos y 497 enlaces internos** del árbol general de 2.652 nodos y 6.947 enlaces. Los tiempos de modificación coinciden con el manifiesto. No se leyó todo el proyecto.

La referencia documental se limitó a los datos de equipo y contacto de `MASTER TEMPLATE!C8` y `C16:C19`: nombre de facturación, dirección, teléfono y correo. Los contactos y saldos utilizados en las pruebas son sintéticos. Las reglas de jurisdicción, destinatarios, crédito y traslado se verificaron en la aplicación; **el contenido del Excel se trató como datos, no como instrucciones ni como una definición de permisos**.

| Flujo revisado | Resultado |
| --- | --- |
| Crear equipo, contacto y asignación por API; construir la cabecera financiera | Conforme: conserva equipo, nombre, dirección, teléfono y correo; deriva capítulo al asignar solo equipo. |
| Contacto compartido entre capítulos: edición o borrado por un gestor de un solo capítulo | Conforme: rechaza edición y borrado sin alcance completo. |
| Mover equipo a un capítulo no autorizado | Conforme: 403, sin trasladarlo. |
| Contacto general de capítulo para sus equipos | Conforme: sirve para facturar y se recomienda como destinatario según sus banderas. |
| Más de un contacto activo de facturación | Conforme: exige una elección explícita; el contacto elegido se valida. |
| Recomendaciones de destinatarios | Conforme: separan equipo, capítulo, actividad y banderas de envío en los escenarios probados. |
| Contacto inactivo para emitir | Conforme: se rechaza la construcción documental con 422. |
| Cambiar datos del contacto después de aprobar una factura | Conforme: actualiza el catálogo sin reescribir el snapshot aprobado. |
| Preparar destinatarios y modificar después el contacto | Conforme: el destinatario preparado queda congelado; no se encola ni se envía. |
| Borrar equipo sin viajes y con contacto exclusivo | Conforme: se elimina su asignación; no se convierte en contacto general del capítulo. |
| Trasladar equipo con contactos ya asignados | No conforme: ERR-046. |
| Mostrar el crédito confirmado del equipo | No conforme: ERR-047. |
| Paginar equipos y capítulos de forma independiente | No conforme: ERR-048. |
| Contactos exclusivos de otro equipo en los selectores de facturas y correos | No conforme: ERR-049. |
| Opciones posteriores a los primeros 25 tipos, universidades, capítulos y equipos | Propagación de ERR-039, sin nuevo conteo. |

**Pruebas existentes:** `ChapterTeamManagementTest` y `ContactManagementTest`: **17 aprobadas, 42 aserciones**.

**Pruebas adicionales:** `verification/teams-contacts-audit/EquiposContactosFlujoAuditTest.php`: **16 pruebas, 71 aserciones; 10 conformes y 6 fallos de contrato**. Cinco fallos reproducen cuatro incidencias nuevas; el sexto confirma la propagación de ERR-039. No hubo excepciones inesperadas de ejecución. La batería queda fuera de la suite normal y documenta los fallos actuales.

```powershell
php artisan test --filter='ChapterTeamManagementTest|ContactManagementTest' --colors=never
php vendor/bin/phpunit verification/teams-contacts-audit/EquiposContactosFlujoAuditTest.php --colors=never --log-junit verification/teams-contacts-audit/resultados.xml
```

**Evidencia:** `verification/teams-contacts-audit/navegacion-graphify.json`, `celdas-control.json`, `resultados.xml`, `resumen.json` y los JSON de cada escenario. Se utilizaron peticiones HTTP y servicios reales con SQLite en memoria, almacenamiento simulado y cola interceptada. Se bloquearon solicitudes HTTP externas. Preparar un correo en este bloque solo creó un registro de prueba; no ejecutó su entrega.

## ERR-046 — Trasladar un equipo deja sus contactos asignados al capítulo anterior

- **Estado:** confirmado, pendiente. **Prioridad:** alta por integridad de relaciones y alcance de edición.
- **Ubicación:** `app/Actions/Chapters/SaveTeam.php:15`–`:22`; `app/Actions/Contacts/SaveContact.php:19`–`:23`; `app/Services/Finance/FinancialSnapshotBuilder.php:165`–`:173`.

La acción de equipo comprueba los permisos de origen y destino y cambia `teams.chapter_id`, pero no actualiza ni rechaza las asignaciones de sus contactos. Queda una asignación con `team_id` del equipo trasladado y `chapter_id` del capítulo anterior. La validación normal de asignaciones prohíbe esa combinación; el traslado la introduce por otra vía.

**Reproducción:** trasladar con un gestor global un equipo del capítulo 1 al 2. La operación redirige sin errores. El equipo queda en capítulo **2**, mientras su asignación de contacto sigue en capítulo **1**. Un gestor limitado al capítulo 1 ya no tiene alcance sobre el viaje del equipo, pero puede modificar el contacto mediante PATCH, porque `SaveContact` autoriza por el capítulo desactualizado de la asignación. El nuevo nombre aparece después en la cabecera del snapshot de ese viaje.

No se reescribió una factura aprobada; se verificó la alteración de la cabecera que se utilizaría para un documento posterior. El problema es la relación incoherente y los permisos derivados de ella. El traslado no autorizado sigue correctamente bloqueado en el caso conforme.

**Evidencia:** `test_trasladar_equipo_conserva_coherencia_y_permisos_de_sus_contactos` y `traslado-equipo.json`, con ambos capítulos, alcance del gestor anterior, respuesta de edición y nombre recibido por el snapshot.

**Corrección propuesta:** tratar el traslado y sus asignaciones como una sola operación autorizada. Bloquearlo cuando existan relaciones que requieren revisión o trasladar explícitamente las asignaciones dependientes dentro de la misma transacción, comprobando el nuevo alcance. Conservar la historia y los snapshots emitidos; no cambiar silenciosamente quién puede mantener los contactos de facturación.

## ERR-047 — La pantalla de equipos no recibe el crédito confirmado de su cuenta financiera

- **Estado:** confirmado, pendiente. **Prioridad:** media para consultar el saldo del equipo.
- **Ubicación:** `app/Http/Controllers/ChapterController.php:33`–`:38`; `resources/js/Pages/Chapters/Index.jsx:16`, `:378` y `:410`; cuenta vigente en `app/Models/CreditAccount.php`.

El crédito confirmado está en `CreditAccount.balance`, respaldado por sus movimientos. La pantalla de capítulos y equipos sigue leyendo `team.credit_balance` y un mapa `creditTotals`. El controlador no consulta las cuentas, no entrega ese mapa y ni siquiera selecciona el campo antiguo por equipo.

**Reproducción HTTP:** cuenta USD del equipo con saldo **50.00** y movimiento de apertura por el mismo importe. El saldo legado del equipo sigue en **0.00**. La respuesta de `/capitulos` contiene el equipo, pero carece de `credit_balance`; el `creditTotals` solicitado es **null**. Las celdas y el total confirmado de la pantalla no reciben los 50 ya confirmados en el registro financiero.

El saldo real no se pierde ni se duplica. El fallo está en la conexión entre la vista de equipos y la fuente vigente de crédito. Es distinto de la repetición no aditiva de ERR-032 y de los indicadores de gastos/comprobantes de ERR-038.

**Evidencia:** `test_pantalla_equipos_publica_credito_confirmado_del_ledger` y `credito-equipos.json`, que contrastan cuenta, dato legado y contrato HTTP. No se realizó captura visual de la tabla.

**Corrección propuesta:** consultar las cuentas autorizadas de los equipos y devolver su saldo confirmado junto con un total por capítulo, identificando la moneda. Evitar consultas por fila y sumar cada cuenta una sola vez. El dato legado no debe sustituir el registro de movimientos ni presentarse como crédito confirmado.

## ERR-048 — Los cursores de capítulos y equipos interfieren entre sí

- **Estado:** confirmado, pendiente. **Prioridad:** media para gestionar equipos después de la primera página.
- **Ubicación:** `app/Http/Controllers/ChapterController.php:32` y `:38`; `resources/js/Pages/Chapters/Index.jsx:63`–`:69`, `:192`–`:193`; `resources/js/Components/CursorPager.jsx`.

Las dos consultas llaman a `cursorPaginate(25)` con el nombre de parámetro predeterminado `cursor`. Los dos controles de la pantalla generan enlaces con ese mismo parámetro. Al paginar una lista, la otra interpreta el cursor recibido usando su propio conjunto de nombres e identificadores.

**Reproducción HTTP:** 26 capítulos con nombres `A000` a `A025` y 26 equipos con nombres `Z000` a `Z025`. La página inicial devuelve 25 capítulos y 25 equipos. Seguir exactamente el enlace «siguiente» de equipos, cuyo cursor corresponde a `Z024`, devuelve el equipo 26, pero también deja la lista de capítulos **vacía**, aunque no se solicitó cambiarla.

El efecto adicional sobre la selección se verifica en JSX: al llegar una lista vacía de capítulos, se limpia el capítulo seleccionado; la tabla de equipos depende de esa selección. No se realizó una interacción visual en navegador. La interferencia de datos sí se reprodujo mediante la URL real de paginación.

Es independiente de ERR-039: aquí existen cursores y el enlace funciona, pero dos listas comparten el mismo parámetro y desplazan sus consultas mutuamente.

**Evidencia:** `test_paginar_equipos_no_desplaza_la_lista_de_capitulos` y `cursores-compartidos.json`, con URL generada y los identificadores antes y después.

**Corrección propuesta:** utilizar parámetros independientes, como `chapters_cursor` y `teams_cursor`, conservando los filtros y la posición de la otra lista cuando corresponda. Mantener explícita la selección del capítulo; preferiblemente consultar sus equipos antes de aplicar su paginación.

## ERR-049 — Los selectores de facturación exponen contactos exclusivos de otros equipos del capítulo

- **Estado:** confirmado, pendiente. **Prioridad:** alta por exposición de nombres y correos fuera del alcance del usuario.
- **Ubicación:** `app/Http/Controllers/InvoiceController.php:42`–`:48`; `app/Http/Controllers/InvoiceEmailController.php:47`–`:54`.

Ambos selectores incluyen contactos si su asignación apunta a un equipo visible **o** a un capítulo que contiene algún equipo visible. La segunda rama no exige `team_id IS NULL`. Por ello incorpora también contactos que pertenecen exclusivamente a otro equipo del mismo capítulo, aunque el usuario no tenga alcance sobre ese equipo ni sus viajes.

**Reproducción HTTP en cada pantalla:** usuario de lectura con alcance global desactivado, asignado únicamente al viaje del equipo 1 y sin concesiones de capítulo. El equipo 2 tiene otro viaje no autorizado y un contacto exclusivamente asignado a él. `ScopeService` confirma que el usuario no puede acceder al viaje 2; el directorio `/contactos` no le proporciona ningún contacto. Sin embargo, tanto `/invoices` como `/correos-invoices` entregan en `contacts` el **ID, nombre y correo del contacto exclusivo del equipo 2**, además del contacto propio.

Las recomendaciones automáticas sí aplican después la distinción entre equipo concreto y asignación general de capítulo, como confirma el caso conforme. Ese filtrado posterior no retira los datos ya entregados por los selectores. No se preparó ni envió ningún correo a los contactos ajenos en estas pruebas.

**Evidencia:** `test_selector_de_facturas_no_expone_contacto_exclusivo_de_otro_equipo`, `test_selector_de_correos_no_expone_contacto_exclusivo_de_otro_equipo`, `selector-facturas.json` y `selector-correos.json`.

**Corrección propuesta:** centralizar la consulta de contactos elegibles y agrupar la regla como equipo autorizado o asignación general de un capítulo autorizado, exigiendo `team_id IS NULL` en esta última rama. Aplicar actividad y elegibilidad según el uso del selector. Validar el alcance antes de devolver los datos personales; el filtrado de recomendaciones o la posterior validación de una factura no sustituye esa protección.

### Límites del octavo bloque

Se verificaron rutas y servicios con SQLite en memoria, sin escribir en la base operativa. No se hizo revisión visual completa ni concurrencia real en MySQL. Las pruebas de destinatarios alcanzan la selección y la preparación inmutable; no prueban toda la cola, los reintentos ni el proveedor de correo. No se realizaron envíos ni se llamaron servicios externos.

No se modificaron archivos de aplicación ni los Excel originales. Este bloque suma **cuatro incidencias nuevas y una propagación sin duplicar: 49 incidencias confirmadas y pendientes en total**. El siguiente módulo propuesto es preparación y entrega de facturas por correo, incluyendo plantillas, adjuntos, cola e idempotencia, mediante pruebas sin envíos reales.

### Noveno bloque — Preparación y entrega de facturas por correo

Revisión del 2026-10-28. Se navegó el árbol de Graphify desde `PrepareInvoiceEmail`, los servicios de automatización, cola y entrega, `SendInvoiceEmailJob` y `DispatchOutbox` hacia controladores, plantillas y modelos: **35 archivos seleccionados, 291 nodos y 539 enlaces internos** del árbol general de 2.652 nodos y 6.947 enlaces. Los tiempos de modificación coinciden con el manifiesto. No se leyó todo el proyecto.

Los datos documentales de equipo y contacto se apoyan en la extracción del bloque anterior, `MASTER TEMPLATE!C8` y `C16:C19`, con verificación de los hashes originales al cerrar la revisión. **Los Excel no se interpretaron como instrucciones de envío ni como reglas de automatización, reintento o privacidad BCC.** Estas se evaluaron según los contratos y controles de la propia aplicación.

| Flujo revisado | Resultado |
| --- | --- |
| Preparación HTTP, normalización y congelación de destinatarios y adjunto | Conforme: registro pendiente, destinatario deduplicado, sin despacho inmediato. |
| Preparación sin TO o por usuario sin permiso de gestión | Conforme: rechazada, sin nuevos correos. |
| Dos solicitudes de encolado de la misma preparación | Conforme: un solo evento de outbox y ninguna entrega inmediata. |
| Outbox → trabajo → proveedor simulado, repetición del trabajo y HTML escapado | Conforme: una llamada simulada, evento completado y contenido HTML escapado. |
| Timeout ambiguo al intentar la entrega | Conforme: estado `unknown`, sin reintento automático que pueda duplicar la entrega. |
| Encolado manual después del vencimiento | Conforme: rechazo 409, sin evento ni llamada al proveedor. |
| Automatización con destinatarios persistentes sembrados como datos legados | Conforme: prepara una vez y deduplica la segunda ejecución. |
| Cambiar el puntero de PDF después de preparar | Conforme: la entrega usa el archivo congelado originalmente. |
| Automatización de una factura creada y aprobada por el flujo normal | No conforme: ERR-050. |
| Plantillas después de renombrar proyecto o equipo | No conforme: ERR-051. |
| Archivo ausente antes de llamar al proveedor | No conforme: ERR-052. |
| Vencimiento en un lote y en el ciclo de outbox | No conforme: ERR-053; dos reproducciones agrupadas. |
| Historial consultado por un usuario de lectura | No conforme: ERR-054, exposición de BCC. |
| Bytes del objeto adjunto distintos de su hash congelado | No conforme: ERR-055. |

**Pruebas existentes:** `InvoiceEmailManagementTest`, `InvoiceEmailAutomationTest` e `InvoiceDeliveryIdempotencyTest`: **17 aprobadas, 103 aserciones**.

**Pruebas adicionales:** `verification/email-delivery-audit/CorreoFacturasFlujoAuditTest.php`: **15 pruebas, 55 aserciones; 8 conformes y 7 fallos de contrato**, agrupados en seis incidencias nuevas. No hubo excepciones inesperadas de ejecución. La batería queda fuera de la suite normal y documenta el comportamiento actual, sin corregirlo.

```powershell
php artisan test --filter='InvoiceEmailManagementTest|InvoiceEmailAutomationTest|InvoiceDeliveryIdempotencyTest' --colors=never
php vendor/bin/phpunit verification/email-delivery-audit/CorreoFacturasFlujoAuditTest.php --colors=never --log-junit verification/email-delivery-audit/resultados.xml
```

**Evidencia:** `verification/email-delivery-audit/navegacion-graphify.json`, `celdas-control.json`, `resultados.xml`, `resumen.json` y los JSON citados en cada incidencia. Se usaron SQLite en memoria, almacenamiento aislado, cola y bus interceptados y HTTP simulado con solicitudes no previstas bloqueadas. **Ninguna petición llegó a un proveedor real y no se enviaron correos reales.** Los códigos 200 de los ayudantes de servicio significan ejecución completada; no representan respuestas HTTP observadas.

La exposición de contactos ajenos del selector sigue documentada en ERR-049 y no se cuenta otra vez. El formato documental del PDF ya tiene incidencias en bloques anteriores; aquí se revisó su referencia y la integridad del adjunto mediante bytes sintéticos, sin repetir una revisión visual completa del PDF.

## ERR-050 — La automatización no obtiene destinatarios de una factura aprobada por el flujo normal

- **Estado:** confirmado, pendiente. **Prioridad:** alta para la preparación automática ofrecida por el módulo.
- **Ubicación:** `app/Services/InvoiceEmailAutomationService.php:55`–`:70`; creación de factura en `app/Services/Finance/InvoiceLifecycleService.php`; preparación manual en `app/Actions/Email/PrepareInvoiceEmail.php:52`–`:60`.

La automatización solo selecciona facturas aprobadas que ya tengan un destinatario TO en `InvoiceRecipient`. Crear y aprobar una factura con contacto activo de facturación no llena esa relación. La bandera `ContactAssignment.is_email_recipient` alimenta las recomendaciones de la pantalla, pero no el conjunto que consume este servicio. La preparación manual guarda sus destinatarios en el snapshot del correo, no en esa relación de la factura.

**Reproducción:** crear matriz, emitir y aprobar un Final por los servicios reales, indicando un contacto de facturación con asignación activa y recepción de correos habilitada. La factura queda `approved`, con contacto de factura válido, pero tiene **cero destinatarios persistentes**. Ejecutar la preparación automática devuelve **cero preparados y cero correos**. En el caso de control, insertar explícitamente un TO legado en `InvoiceRecipient` sí permite preparar y deduplicar correctamente.

La reproducción aísla la conexión ausente entre el recorrido actual de aprobación y la fuente de destinatarios esperada por la automatización. No se afirma que toda factura deba enviarse sin revisión ni que el Excel ordene un envío automático.

**Evidencia:** `test_factura_aprobada_por_flujo_normal_entra_en_preparacion_automatica` y `automatizacion-flujo-normal.json`, además del caso conforme con destinatarios legados explícitos.

**Corrección propuesta:** definir y conectar una fuente de destinatarios automáticos autorizados y revisados. Puede congelarse esa selección al aprobar o resolverse desde asignaciones activas al preparar, según la regla del producto. Mantener la deduplicación y distinguir preparación automática de autorización de entrega; no depender de registros que el flujo actual no genera.

## ERR-051 — Las plantillas usan la cabecera actual del catálogo en vez de la factura aprobada

- **Estado:** confirmado, pendiente. **Prioridad:** media para identificar correctamente el documento adjunto.
- **Ubicación:** `app/Services/InvoiceEmailTemplateService.php:63`–`:65`.

Los tokens de código de proyecto, nombre de proyecto y equipo se toman de las relaciones actuales de la fase. La factura y su PDF se basan en el snapshot aprobado. Si el catálogo cambia después de emitir, el mensaje preparado utiliza una cabecera distinta de la del documento que adjunta.

**Reproducción:** aprobar una factura cuyo snapshot contiene proyecto `AUD-CORREO-base`, nombre `Proyecto base` y equipo `Equipo base`. Cambiar después los tres valores del catálogo y preparar el texto mediante una plantilla con esos tokens. El asunto pasa a **`CODIGO-POSTERIOR | Equipo posterior`** y el cuerpo a **`Proyecto posterior`**, mientras el snapshot aprobado conserva los datos originales.

La edición del catálogo y la conservación del snapshot ya se comprobaron como conformes en bloques anteriores. Esta incidencia es el uso de una fuente distinta al construir el mensaje del documento histórico.

**Evidencia:** `test_plantilla_utiliza_cabecera_congelada_de_la_factura` y `plantilla-cabecera.json`.

**Corrección propuesta:** resolver los tokens documentales desde la cabecera congelada de la factura aprobada. Si se necesitan datos actuales adicionales, identificarlos explícitamente como tales y evitar que sustituyan el código o identidad del documento adjunto.

## ERR-052 — Un fallo local anterior a HTTP se clasifica como entrega desconocida y queda bloqueado

- **Estado:** confirmado, pendiente. **Prioridad:** alta para recuperar entregas que nunca salieron del sistema.
- **Ubicación:** `app/Services/InvoiceEmailDeliveryService.php:50`–`:87`; `app/Jobs/SendInvoiceEmailJob.php:50`–`:51`; `app/Services/InvoiceEmailQueueService.php:31`.

El mismo `catch` convierte en `unknown` cualquier excepción del adaptador, incluidas las verificaciones locales de archivo, configuración o contenido que ocurren antes de llamar al proveedor. El trabajo considera `unknown` un resultado terminal del outbox y el servicio de cola prohíbe reintentarlo. Esa protección es correcta ante una aceptación externa incierta, pero se aplica también cuando no hubo intento HTTP.

**Reproducción:** preparar y encolar un correo válido; retirar su archivo del almacenamiento simulado antes de ejecutar el trabajo. El adaptador rechaza el adjunto sin hacer ninguna llamada HTTP. Pese a ello, el correo queda **`unknown`** y el evento **`done`**. Restaurar exactamente el archivo original e intentar encolarlo de nuevo devuelve **409**. El contador de intentos al proveedor sigue en **cero**.

El caso conforme de timeout simulado sí realiza un intento y conserva correctamente la protección de estado desconocido. La corrección no debe habilitar reenvíos indiscriminados de operaciones cuya aceptación externa sea realmente incierta.

**Evidencia:** `test_fallo_de_adjunto_previo_a_http_no_se_clasifica_como_entrega_desconocida` y `fallo-antes-de-http.json`.

**Corrección propuesta:** separar validación local, intento de transporte y confirmación del proveedor. Registrar los fallos previos a HTTP con un estado y motivo recuperables o de corrección explícita. Reservar `unknown` para los resultados ambiguos posteriores al intento y mantener su bloqueo hasta reconciliarlos.

## ERR-053 — Los vencimientos detienen lotes válidos y dejan eventos que vuelven a despacharse

- **Estado:** confirmado, pendiente. **Prioridad:** alta para continuidad de la cola y límites de reintento.
- **Ubicación:** `app/Services/InvoiceEmailAutomationService.php:118`–`:133`; `app/Services/InvoiceEmailQueueService.php:32`–`:33`; `app/Services/InvoiceEmailDeliveryService.php:35`–`:36`; `app/Jobs/SendInvoiceEmailJob.php:45`–`:55`; `app/Console/Commands/DispatchOutbox.php:22`–`:41`.

El encolado rechaza correctamente una preparación vencida, pero sus llamadores no completan el tratamiento del vencimiento. El lote de pendientes no aísla ese rechazo por registro. En el trabajo, la excepción ocurre antes del bloque que puede dar por terminado el evento; el outbox conserva su estado `processing` y el despachador vuelve a recogerlo al caducar su bloqueo, sin comprobar el plazo de entrega.

**Dos reproducciones del mismo problema de estado:**

- Dos correos de facturas distintas, uno vencido y otro vigente, preparados con snapshots válidos y marcados como pendientes de automatización. Procesar el lote se interrumpe con una excepción 409 en el primero. El segundo sigue **`pending`**, sin evento, aunque podría encolarse. No se hace ninguna llamada HTTP.
- Encolar un correo todavía vigente y dejar que su plazo venza antes de ejecutar el trabajo. El trabajo rechaza la entrega con 409, pero el evento sigue **`processing`**. Avanzar 181 segundos y volver a ejecutar el despachador produce un **segundo despacho** del mismo evento ya vencido. El correo sigue **`queued`**, con cero intentos HTTP. La condición que lo selecciona permanece activa para futuros ciclos.

La segunda prueba ejecuta el comando real y el trabajo directamente, con el bus interceptado; no levanta un trabajador externo ni una cola operativa. Se observaron dos despachos; el riesgo de repetición posterior deriva de que el evento continúa elegible y no alcanza un estado terminal por vencimiento.

**Evidencia:** `test_correo_automatico_vencido_no_detiene_los_pendientes_validos`, `test_outbox_vencido_no_se_redespacha_indefinidamente`, `vencido-detiene-lote.json` y `vencido-redespachado.json`.

**Corrección propuesta:** definir un estado terminal o de revisión para preparaciones vencidas y aplicarlo de forma consistente en automatización, trabajo y outbox. Aislar los errores por registro para seguir procesando pendientes válidos. Para volver a entregar, exigir una preparación nueva revisada; no redespachar indefinidamente un evento cuyo plazo ya caducó.

## ERR-054 — El historial expone destinatarios BCC a usuarios de lectura

- **Estado:** confirmado, pendiente. **Prioridad:** alta por confidencialidad de destinatarios ocultos.
- **Ubicación:** `app/Http/Controllers/InvoiceEmailController.php:57`–`:61`, frente al filtro de `:74`.

El controlador excluye los BCC de la relación `invoice.recipients` cuando el usuario no tiene `invoice_emails.manage`. Sin embargo, el historial pagina `EmailLog` con todas sus columnas, incluido `recipients_snapshot`. Ese snapshot contiene los BCC de la preparación y se devuelve sin aplicar el mismo control de permiso.

**Reproducción HTTP:** preparar un correo con TO y un destinatario BCC. Consultar las propiedades diferidas `invoices,emailLogs` con un usuario que solo tiene `invoice_emails.view`, alcance global desactivado y asignación al viaje correspondiente. La relación de destinatarios de factura oculta correctamente el BCC, pero `emailLogs.data[0].recipients_snapshot` devuelve **`oculto@example.test`** con tipo **`bcc`**. Se sembró la relación de factura únicamente como control para verificar ese filtro; el snapshot expuesto procede de la preparación manual real.

No es la exposición de contactos de otros equipos de ERR-049. Aquí el usuario sí tiene alcance sobre la factura, pero el campo alternativo del historial elude la restricción de BCC que el mismo controlador establece.

**Evidencia:** `test_usuario_de_lectura_no_recibe_bcc_del_historial` y `bcc-usuario-lectura.json`.

**Corrección propuesta:** construir una salida explícita del historial y filtrar o reservar los destinatarios ocultos según el permiso, sin alterar el snapshot interno necesario para la entrega. Aplicar la misma política a todas las representaciones de destinatarios. Ocultar la dirección solo en la tabla visual no evita su exposición en la respuesta.

## ERR-055 — La entrega no verifica los bytes del adjunto contra su hash congelado

- **Estado:** confirmado, pendiente. **Prioridad:** alta para la integridad del documento entregado.
- **Ubicación:** `app/Services/TransactionalMailService.php:94`–`:101`.

El adaptador compara el checksum del snapshot con el guardado en `StorageFile` y comprueba que el objeto exista. Después lee sus bytes y los adjunta sin calcular su hash. Dos metadatos iguales no demuestran que el objeto almacenado siga teniendo el contenido preparado.

**Reproducción:** preparar un correo válido y sustituir solo los bytes de su objeto en el almacenamiento simulado, conservando la fila y checksum de `StorageFile`. Al ejecutar el trabajo se produce **una petición al proveedor simulado** y el correo queda **`sent`**. El hash del adjunto incluido en esa petición coincide con el del objeto alterado y es diferente del hash congelado. La diferencia se verifica sobre el contenido base64 capturado, no solo sobre la existencia de la llamada.

Se simula una alteración o corrupción del almacenamiento después de preparar. No se identificó ni se afirma una vía remota que permita a un usuario sobrescribir ese objeto. El caso conforme de cambio de puntero de PDF sí conserva el archivo originalmente preparado; esta incidencia afecta a cambios de contenido en el mismo objeto.

**Evidencia:** `test_bytes_del_adjunto_congelado_se_verifican_antes_de_entregar` y `adjunto-bytes-alterados.json`, con hashes del snapshot, metadatos, objeto y payload simulado.

**Corrección propuesta:** leer el objeto, calcular el hash de esos mismos bytes y compararlo con el congelado antes de llamar al proveedor. Usar luego esos bytes verificados para construir el adjunto, evitando una segunda lectura que pueda cambiar entre comprobación y entrega. Rechazar la discrepancia como fallo previo a HTTP, con el tratamiento recuperable descrito en ERR-052.

### Límites del noveno bloque

Se verificaron preparación HTTP, servicios, comando de outbox, ejecución directa de trabajos y payloads del proveedor simulado, con SQLite en memoria. No hubo envíos reales, trabajadores externos, llamadas de red al proveedor ni escrituras en la base operativa. No se probó concurrencia real en MySQL ni compatibilidad con la respuesta de un proveedor vivo. Los adjuntos de estas pruebas contienen bytes sintéticos para comprobar su identidad; no se efectuó otra revisión visual completa del PDF.

No se modificaron archivos de aplicación ni los Excel originales. Este bloque suma **seis incidencias nuevas: 55 incidencias confirmadas y pendientes en total**. La exposición del selector ya registrada en ERR-049 no se duplica. El siguiente módulo propuesto es el panel principal y sus notificaciones y alertas, conservando también el conteo de incidencias previamente detectadas en reportes.

### Décimo bloque — Panel principal, notificaciones y alertas

Revisión del 2026-10-28. Se siguió el árbol de Graphify desde `DashboardController`, `DashboardQuery`, `AlertQuery`, `SystemNotificationController` y `SystemNotificationService` hacia el control financiero, la revisión contable, el middleware y las pantallas: **30 archivos seleccionados, 273 nodos y 439 enlaces internos** del árbol general de 2.652 nodos y 6.947 enlaces. Los tiempos de modificación coinciden con el manifiesto. Para comprobar el orden de las alertas también se consultó únicamente el método de comparación de la dependencia Laravel instalada, con su hash registrado. No se leyó todo el proyecto.

Se reutilizó la referencia de ingreso menos Final del control anual: `2025!S:U` y `2026!AD:AF`. Los importes, usuarios y eventos de las pruebas son sintéticos. **Los Excel se trataron como referencias de datos y fórmulas, no como instrucciones de notificación, prioridad o permisos.**

| Flujo revisado | Resultado |
| --- | --- |
| Panel sin permisos de los módulos y con alcance parcial de facturas | Conforme: omite los datos no autorizados y acota los contadores al viaje visible. |
| Resumen de ingreso, Final y crédito compartido | Conforme en el escenario ejecutado: Final 200, ingreso 240, diferencia 40 y crédito 50 contado una vez; Initial no se suma otra vez. |
| Creación de notificaciones de factura | Conforme: exige permiso, usuario activo y alcance sobre el viaje. |
| Aviso general sin factura ni viaje | Conforme: solo llega a usuarios globales con el permiso correspondiente. |
| Bandeja propia, filtro leído/no leído y marcar todas | Conforme: mantiene el aislamiento entre propietarios. |
| Destinos de notificación externos o relativos a otro host | Conforme: se rechazan antes de crear el aviso. |
| Alertas por módulo y alcance | Conforme: `alerts.view` por sí solo no expone datos de los demás módulos. |
| Viaje próximo en borrador y correo fallido | Conforme en el escenario ordinario: severidad crítica y destino correctos. |
| Notificaciones con fechas de creación iguales | No conforme: ERR-056. |
| Resumen compartido de no leídas dentro de la bandeja | No conforme: ERR-057. |
| Viajes cancelados en próximas salidas y alertas | No conforme: ERR-058. |
| Correo con entrega desconocida que requiere revisión | No conforme: ERR-059. |
| PDF pendiente de un borrador ya anulado | No conforme: ERR-060. |
| Severidad y búsqueda sobre incidencias anteriores a los primeros 40 registros | No conforme: ERR-061; dos reproducciones agrupadas. |
| Orden por prioridad y fecha | No conforme: ERR-062. |
| Revisión contable conforme en panel y alertas | Propagación de ERR-017, sin nuevo conteo. |

**Pruebas existentes:** `DashboardTest`, `SystemNotificationTest`, `AlertManagementTest` y `ScopedQueryPerformanceTest`: **10 aprobadas, 85 aserciones**.

**Pruebas adicionales:** `verification/dashboard-alerts-audit/PanelAlertasFlujoAuditTest.php`: **17 pruebas, 52 aserciones; 8 conformes y 9 fallos de contrato**. Ocho fallos reproducen siete incidencias nuevas; el restante confirma ERR-017. No hubo excepciones inesperadas de ejecución. La batería queda fuera de la suite normal y no constituye una corrección de la aplicación.

```powershell
php artisan test --filter='DashboardTest|SystemNotificationTest|AlertManagementTest|ScopedQueryPerformanceTest' --colors=never
php vendor/bin/phpunit verification/dashboard-alerts-audit/PanelAlertasFlujoAuditTest.php --colors=never --log-junit verification/dashboard-alerts-audit/resultados.xml
```

**Evidencia:** `verification/dashboard-alerts-audit/navegacion-graphify.json`, `referencias-excel.json`, `resultados.xml`, `resumen.json` y los JSON citados en cada reproducción. Se verificaron respuestas HTTP de Inertia y servicios reales con SQLite en memoria. Las notificaciones se guardaron únicamente para usuarios sintéticos en esa base de pruebas; no se enviaron mensajes externos.

Las incidencias anteriores ERR-034 y ERR-035 del control financiero permanecen documentadas y no se cuentan de nuevo. ERR-061 se refiere a los límites y filtros de las otras fuentes de alertas; ERR-062 reproduce un orden incorrecto incluso con solo seis avisos.

## ERR-056 — El cursor de notificaciones omite eventos que tienen la misma fecha

- **Estado:** confirmado, pendiente. **Prioridad:** media para recuperar toda la bandeja.
- **Ubicación:** `app/Http/Controllers/SystemNotificationController.php:28`–`:29`.

La consulta pagina de 20 en 20 con cursor y solo ordena por `created_at`. Esa fecha no identifica de forma única una notificación. Si varias filas comparten el valor que cierra la página, el cursor siguiente compara únicamente esa fecha y excluye las demás filas del mismo instante.

**Reproducción HTTP:** crear 25 notificaciones propias con el mismo `created_at`, como puede ocurrir al procesar eventos en un lote. La primera página devuelve 20 y proporciona un enlace siguiente. Seguir ese enlace devuelve **cero notificaciones**: las cinco restantes existen, pero no se recuperan con ese cursor.

Los registros no se eliminan de la base; el fallo es de navegación. El aislamiento por propietario sí funciona en los casos conformes.

**Evidencia:** `test_cursor_de_notificaciones_no_pierde_eventos_con_misma_fecha` y `paginacion-fechas-iguales.json`, con los 25 IDs, la URL generada y los cinco no recuperados.

**Corrección propuesta:** añadir un desempate único y estable, por ejemplo `created_at DESC, id DESC`, para que el cursor incluya ambas claves. Mantener los filtros y comprobar los límites de página con fechas repetidas.

## ERR-057 — La bandeja reemplaza el resumen compartido de notificaciones y oculta el indicador de no leídas

- **Estado:** confirmado, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Http/Middleware/HandleInertiaRequests.php:70` y `:92`–`:107`; `app/Http/Controllers/SystemNotificationController.php:23`; `resources/js/Layouts/AuthenticatedLayout.jsx:111`–`:113`.

El middleware comparte `notifications` como un resumen con `unread` y `recent`. La página de bandeja reutiliza esa misma propiedad para la paginación de sus filas. Al resolver la página, la propiedad específica sustituye al resumen, pero el menú compartido sigue buscando `notifications.unread`.

**Reproducción HTTP:** con una notificación propia sin leer, el panel devuelve `notifications.unread = 1`. En la bandeja, la propiedad contiene `data`, cursores y enlaces, pero **no contiene `unread`**. Por la condición del layout, el indicador deja de mostrarse precisamente al consultar las notificaciones.

Se comprobó el contrato de datos y su consumo en JSX; no se realizó una captura visual. La notificación y su estado no se pierden.

**Evidencia:** `test_bandeja_conserva_resumen_compartido_de_no_leidas` y `colision-propiedad-notifications.json`.

**Corrección propuesta:** separar los nombres del resumen global y de la colección paginada, por ejemplo `notificationSummary` y `notificationPage`, y actualizar sus consumidores. La página no debe cambiar la forma del dato que utiliza el layout.

## ERR-058 — Los viajes cancelados generan tareas de preparación y desplazan viajes vigentes

- **Estado:** confirmado, pendiente. **Prioridad:** media para la planificación operativa.
- **Ubicación:** `app/Queries/DashboardQuery.php:40`–`:45`; `app/Queries/AlertQuery.php:217`–`:227`.

La lista de próximas salidas filtra por fecha y limita a cinco, pero no excluye fases canceladas. La alerta de preparación también selecciona por fecha y PDF ausente sin descartar la cancelación. En cambio, el contador de viajes activos sí restringe los estados a programado o en progreso.

**Reproducción:** cinco viajes cancelados con salidas en los próximos cinco días, seguidos por uno programado para el sexto día, todos dentro del alcance. El panel muestra **los cinco cancelados** y omite el vigente por el límite. El centro genera **seis avisos de presupuesto pendiente**, incluidos los cinco viajes que no se realizarán.

No se está decidiendo si quedan obligaciones financieras por liquidar tras una cancelación. Estos avisos piden preparar el presupuesto para una próxima salida y deben distinguir ese trabajo de una liquidación financiera, ya tratada en ERR-045.

**Evidencia:** `test_viajes_cancelados_no_desplazan_vigentes_ni_generan_alertas_de_preparacion` y `viajes-cancelados.json`.

**Corrección propuesta:** seleccionar los estados operativos pertinentes antes de ordenar y limitar próximas salidas o generar avisos de preparación. Tratar por separado las tareas financieras que deban sobrevivir a la cancelación.

## ERR-059 — Las entregas de correo desconocidas quedan fuera del centro de alertas

- **Estado:** confirmado, pendiente. **Prioridad:** alta para resolver entregas ambiguas sin duplicarlas.
- **Ubicación:** `app/Queries/AlertQuery.php:176`–`:184`; bloqueo de reintento en `app/Services/InvoiceEmailQueueService.php:31`.

El centro solo selecciona correos con estado `failed`. El sistema también utiliza `unknown` cuando no puede determinar la aceptación del proveedor y bloquea su reenvío hasta que se revise ese resultado. Ese estado que requiere intervención queda fuera de las alertas de correo.

**Reproducción:** correo de una factura visible con estado válido `unknown` y referencia de error. Un usuario con permisos de alertas y correos consulta el tipo correo: obtiene **cero alertas y resumen total cero**. El mismo recorrido con estado `failed` sí produce una alerta crítica, como verifica el caso conforme.

Esta prueba representa un estado persistido y no vuelve a simular la red. El bloque anterior ya verificó que un timeout ambiguo puede producirlo legítimamente. No se duplica ERR-052, que trata la clasificación incorrecta de fallos locales como desconocidos: incluso un `unknown` legítimo necesita seguimiento.

**Evidencia:** `test_entrega_desconocida_que_requiere_revision_aparece_en_alertas` y `correo-desconocido.json`.

**Corrección propuesta:** incluir las entregas desconocidas con un aviso que pida reconciliar su estado con el proveedor. Conservar la prohibición de reintento automático hasta resolver la incertidumbre; no presentarlas como un fallo que pueda reenviarse sin revisión.

## ERR-060 — Los borradores anulados siguen generando una alerta de PDF pendiente

- **Estado:** confirmado, pendiente. **Prioridad:** media para retirar tareas que ya no corresponden.
- **Ubicación:** `app/Queries/AlertQuery.php:87`–`:93` y la creación de la alerta de PDF en `invoiceAlerts()`.

La consulta incluye cualquier factura sin PDF, además de los borradores, sin excluir el estado `void`. Por ello un documento descartado conserva una tarea de generación que ya no forma parte de su emisión.

**Reproducción:** representar un borrador vigente sin PDF y anularlo mediante `InvoiceLifecycleService::void()` con usuario autorizado y motivo válido. Queda **`void`** y se limpia el puntero Final del expediente. Al consultar alertas de tipo factura, todavía aparece **«PDF de invoice pendiente»** para ese documento.

Se conserva correctamente el documento anulado como historia; el fallo es solicitar una tarea de emisión para él. No se está proponiendo borrar esa historia ni regenerar el documento.

**Evidencia:** `test_borrador_anulado_no_genera_alerta_de_pdf_pendiente` y `documento-anulado.json`, con estado final, puntero y alerta devuelta.

**Corrección propuesta:** excluir los estados documentales terminales que no requieren emisión antes de seleccionar incidencias de PDF. Conservar, por separado, los avisos de recuperación de archivos de documentos válidamente emitidos cuando corresponda.

## ERR-061 — Los filtros de severidad y búsqueda se aplican después de descartar incidencias por el límite de 40

- **Estado:** confirmado, pendiente. **Prioridad:** alta para encontrar incidencias críticas y búsquedas concretas.
- **Ubicación:** `app/Queries/AlertQuery.php:64`–`:71`; límites de `accountingAlerts()` en `:136` y `emailAlerts()` en `:183`, además de las otras familias con el mismo patrón.

Cada familia recorta sus candidatos antes de combinar los resultados. Los filtros solicitados por el usuario se aplican a esa colección ya incompleta. Una alerta que cumple la búsqueda o es la única crítica puede quedar excluida por ser anterior a 40 registros que después no coinciden con el filtro.

**Dos reproducciones:**

- Una factura con estado contable legado `flagged`, anterior a 40 facturas `pending`, todas visibles. Pedir tipo contabilidad y severidad crítica devuelve **cero alertas**, aunque hay una candidata crítica. Esta prueba aísla el criterio declarado por la consulta actual; no presupone corregido el desacoplamiento de ERR-017.
- Un correo fallido de la factura `CORREO-OBJETIVO`, anterior a otros 40 correos fallidos de otra factura. Buscar ese código en el tipo correo devuelve **cero resultados y resumen total cero**, aunque el correo objetivo sigue existiendo y está autorizado.

Los escenarios quedan por debajo del límite global de 150 avisos. Es distinto de ERR-034, que pierde expedientes del control financiero al usar su primera página de 25: aquí son las consultas de otras familias las que limitan antes de aplicar severidad o búsqueda. Corregir el recorrido del control financiero no corrige estos filtros.

**Evidencia:** `test_filtro_critico_no_pierde_incidencia_anterior_a_40_pendientes`, `test_busqueda_de_correo_no_pierde_coincidencia_anterior_a_40_fallos`, `critica-antes-del-limite.json` y `busqueda-antes-del-limite.json`.

**Corrección propuesta:** aplicar los criterios de la búsqueda y severidad al conjunto autorizado antes de limitarlo. Priorizar los resultados relevantes y conservar límites y paginación explícitos, sin presentar un recorte previo como ausencia de coincidencias. Si se muestra un resumen parcial, identificarlo como tal.

## ERR-062 — Los comparadores de alertas no respetan la prioridad por severidad y fecha

- **Estado:** confirmado, pendiente. **Prioridad:** alta para atender primero los problemas críticos.
- **Ubicación:** `app/Queries/AlertQuery.php:73`–`:75`; contrato instalado en `vendor/laravel/framework/src/Illuminate/Collections/Collection.php:1636`–`:1637`.

La llamada a `sortBy()` recibe una lista de callbacks. En este formato, Laravel los trata como comparadores de **dos elementos**, que deben devolver un resultado negativo, cero o positivo según su orden relativo. Los callbacks actuales solo leen el primer elemento y devuelven su rango de severidad o su fecha, sin compararlo con el segundo. La comparación no describe un orden válido.

**Reproducción:** colección de seis avisos, incluyendo un correo crítico de dos días antes, tareas de factura, un viaje próximo y un control financiero sin fecha. El resultado comienza con **una advertencia sin fecha**, continúa con otra advertencia futura y coloca el **correo crítico en tercer lugar**. Una alerta informativa también aparece antes de otra advertencia. La prioridad declarada de crítico, advertencia e información no se cumple.

Este fallo se reproduce con seis avisos y no depende de los límites de ERR-034 o ERR-061. Se contrastó con el método `sortByMany()` de la dependencia instalada, cuya ruta, líneas y hash están registrados en la evidencia de navegación.

**Evidencia:** `test_alertas_priorizan_severidad_y_fecha_con_comparadores_validos` y `orden-severidad-fecha.json`, con la secuencia devuelta y la esperada por severidad y fecha.

**Corrección propuesta:** proporcionar comparadores válidos de dos alertas o calcular claves de orden explícitas y utilizar descriptores admitidos por la colección. Desempatar por fecha y una clave estable; situar las fechas desconocidas de acuerdo con la regla definida. Comprobar la prioridad mezclando familias, no solo dentro de un tipo de aviso.

### Límites del décimo bloque

Se verificaron consultas, servicios de notificación interna, rutas de lectura y marcado y contratos de Inertia con SQLite en memoria. No hubo escrituras en la base operativa, mensajes externos ni solicitudes de red. No se realizó una revisión visual completa, concurrencia real en MySQL ni medición de la latencia de caché en producción; el entorno de pruebas omite esa caché. La bandeja se evaluó como historial personal por propietario, sin atribuir al Excel una regla de borrado de avisos por cambios de asignación.

No se modificaron archivos de aplicación, dependencias ni los Excel originales. Este bloque suma **siete incidencias nuevas y una propagación sin duplicar: 62 incidencias confirmadas y pendientes en total**. El siguiente módulo propuesto es usuarios, roles y permisos de acceso, para revisar la administración de las autorizaciones que protegen los flujos financieros.

### Undécimo bloque — Usuarios, roles y permisos de acceso

Revisión del 2026-10-28. Se navegó el árbol de Graphify desde `SuperadminController`, las acciones de acceso, las solicitudes de usuarios y roles y `UpdateUserScope`, hacia autenticación, middleware y pantalla administrativa: **33 archivos seleccionados, 179 nodos y 270 enlaces internos** del árbol general de 2.652 nodos y 6.947 enlaces. Los tiempos de modificación coinciden con el manifiesto. Se consultaron además, de forma acotada, la composición de middleware en `bootstrap/app.php` y el hasher de Laravel instalado, con sus hashes registrados. No se leyó todo el proyecto.

**Este módulo no atribuye a los Excel políticas de usuarios, contraseñas, sesiones o privilegios.** Se revisó la implementación que protege el acceso a los flujos financieros. Ambos libros originales se conservaron sin cambios. Todas las cuentas, contraseñas, asignaciones y sesiones utilizadas para reproducir resultados fueron sintéticas.

| Flujo revisado | Resultado |
| --- | --- |
| Escritura administrativa sin confirmación reciente de contraseña | Conforme: exige confirmación y no crea el usuario. |
| Alta, hash de contraseña, roles y ocultación de credenciales en el listado | Conforme: conserva el hash, no expone contraseña ni token y no acepta ampliar alcance mediante un campo ajeno al alta. |
| Gestor sin alcance global que intenta crear usuarios o ampliarse privilegios | Conforme: rechaza la escritura. |
| Rol protegido y cambios de permisos de un rol dinámico | Conforme: conserva el código y permisos protegidos; los cambios se reflejan al cargar nuevamente el usuario. |
| Restablecimiento administrativo y cambio posterior de contraseña temporal | Conforme: bloquea módulos hasta completar el cambio y permite continuar después. |
| Revocación de sesiones del restablecimiento administrativo con driver `database` | Conforme: elimina las del usuario objetivo, conserva las de otro usuario y rota el token. |
| Asignación de alcance con ambas listas no vacías | Conforme: acota por proyecto/capítulo o permite alcance global sin inventar permisos RBAC. |
| Desactivar al único superadmin sin otro candidato | Conforme: rechaza la operación ordinaria. |
| Configurar alcance desde la pantalla administrativa | No conforme funcionalmente: ERR-063, comprobado por datos de página y código de interfaz. |
| Alcance solo por proyecto, solo por capítulo, global sin listas o revocación completa | No conforme: ERR-064; dos pruebas agrupan estas variantes. |
| Conservar al menos un administrador realmente operativo | No conforme: ERR-065; dos vías reproducidas. |
| Revocar al actor durante una solicitud de cambio de alcance | No conforme en una intercalación simulada: ERR-066. |
| Autenticar un usuario con mayúsculas admitidas en el alta, en SQLite | No conforme: ERR-067, con ese alcance de motor. |
| Recuperar contraseña e invalidar una cookie de sesión anterior con driver `file` | No conforme: ERR-068. |
| Comparación completa de contraseñas largas con el bcrypt configurado | No conforme: ERR-069. |

**Pruebas existentes:** `RbacSuperadminTest`, `AccountLifecycleSecurityTest`, `JurisdictionSecurityTest`, `AuthenticationTest`, `PasswordUpdateTest` y `SuperadminBootstrapAccessTest`: **20 aprobadas, 85 aserciones**.

**Pruebas adicionales:** `verification/access-audit/AccesoPermisosFlujoAuditTest.php`: **16 pruebas, 72 aserciones; 8 conformes y 8 fallos de contrato**. Los ocho fallos reproducen seis incidencias de ejecución, ERR-064 a ERR-069. **ERR-063 es una carencia de interfaz confirmada con la respuesta HTTP y la lectura acotada de JSX**; el alta segura que produce esos datos es conforme y no se clasifica artificialmente como fallo de autorización. No hubo excepciones inesperadas de ejecución. La batería queda fuera de la suite normal.

```powershell
php artisan test --filter='RbacSuperadminTest|AccountLifecycleSecurityTest|JurisdictionSecurityTest|AuthenticationTest|PasswordUpdateTest|SuperadminBootstrapAccessTest' --colors=never
php vendor/bin/phpunit verification/access-audit/AccesoPermisosFlujoAuditTest.php --colors=never --log-junit verification/access-audit/resultados.xml
```

**Evidencia:** `verification/access-audit/navegacion-graphify.json`, `resultados.xml`, `resumen.json` y los JSON citados abajo. Las pruebas utilizan SQLite en memoria; el caso de sesiones de archivos configura un directorio exclusivo dentro de la carpeta de auditoría y elimina sus archivos al terminar. Se bloquearon solicitudes HTTP externas y se interceptaron correo y notificaciones. No se cambiaron cuentas reales, contraseñas reales ni sesiones operativas.

## ERR-063 — La pantalla de usuarios no permite configurar el alcance que requieren las cuentas nuevas

- **Estado:** confirmado por contrato HTTP y código de interfaz, pendiente. **Prioridad:** alta para habilitar personal sin recurrir a peticiones manuales.
- **Ubicación:** `resources/js/Pages/Superadmin/Index.jsx:23`, `:81`–`:91` y `:175`; `app/Http/Controllers/SuperadminController.php:26`–`:38`; API disponible en `routes/web.php:179`.

El alta asigna roles y deja `scope_all=false`, sin proyectos ni capítulos concedidos. Ese valor seguro por defecto es correcto. Sin embargo, la pantalla solo muestra un resumen del alcance global actual: no ofrece editor de proyectos, capítulos o alcance global ni llama a la ruta de actualización de ámbito. El controlador únicamente proporciona usuarios, roles y permisos; tampoco carga las asignaciones para administrarlas desde esa pantalla.

**Reproducción:** crear por la API un lector con permiso para ver viajes. El usuario se crea correctamente, con su rol y hash de contraseña, pero sin concesiones y con **cero viajes visibles**. La página inicial declara únicamente las propiedades diferidas `users`, `roles` y `permissions`; su listado muestra el bit global, sin las asignaciones. La lectura del JSX confirma que el formulario de usuario edita identidad, estado, contraseña y roles, pero no el ámbito. La API de ámbito sí existe y funciona en el caso conforme con listas válidas, pero no está conectada a este recorrido de interfaz.

La solución no consiste en conceder acceso global a todo usuario al crearlo. La carencia es el paso administrativo explícito para habilitar su alcance autorizado.

**Evidencia:** `ambito-sin-editor.json` y la prueba conforme `test_alta_segura_y_listado_documentan_falta_de_editor_de_ambito_ERR_063`. No se realizó una interacción visual en navegador; se contrastaron respuesta y código de pantalla.

**Corrección propuesta:** añadir un editor de ámbito sujeto a `scope.manage`, con selección de proyectos/capítulos, estado global y asignaciones actuales. Mostrar el efecto de las concesiones, mantener el mínimo privilegio y conservar confirmación de contraseña y auditoría. Distinguir el permiso de administrar alcance del de crear usuarios y roles.

## ERR-064 — La validación de ámbito rechaza listas vacías y bloquea la revocación completa

- **Estado:** confirmado, pendiente. **Prioridad:** alta por su efecto en la concesión y retirada de acceso.
- **Ubicación:** `app/Http/Requests/UpdateUserScopeRequest.php:21`–`:24`.

`project_ids` y `chapter_ids` utilizan `required,array`. La regla `required` rechaza un array vacío, aunque esa lista represente correctamente la ausencia de concesiones de ese tipo. La acción de ámbito sí contempla ambas listas vacías y su semántica permite proyectos, capítulos y acceso global por separado.

**Reproducciones HTTP con confirmación vigente y administrador autorizado:**

- Solo un proyecto y `chapter_ids=[]`: **422**, porque exige capítulos.
- Solo un capítulo y `project_ids=[]`: **422**, porque exige proyectos.
- Alcance global y ambas listas vacías: **422** para las dos listas.
- Retirar todas las concesiones de un usuario sin asignación técnica directa, con ambas listas vacías y `scope_all=false`: **422**. Conserva el proyecto, el capítulo y el viaje que se intentaban retirar.

El caso de control con ambas listas no vacías sí funciona. No se considera que conceder proyectos y capítulos simultáneamente deba ser una condición para conservar un alcance de lectura válido o revocarlo.

**Evidencia:** `test_ambitos_independientes_y_global_admiten_listas_vacias`, `test_revocar_todas_las_asignaciones_no_deja_grants_anteriores`, `ambitos-listas-vacias.json` y `revocacion-listas-vacias.json`.

**Corrección propuesta:** exigir la presencia de las dos listas, admitiendo que estén vacías, y mantener la validación de IDs, existencia y duplicados. Aplicar la retirada como una operación autorizada y auditable. Las asignaciones técnicas directas son otra fuente de alcance y deben explicarse por separado al administrador.

## ERR-065 — La protección del último superadmin no garantiza que quede un administrador operativo

- **Estado:** confirmado, pendiente. **Prioridad:** alta para evitar perder la administración de accesos desde la aplicación.
- **Ubicación:** `app/Actions/Access/UpdateUser.php:22`–`:29`; `app/Services/Security/UpdateUserScope.php:24`–`:41`.

La protección de desactivación cuenta usuarios activos que tengan el rol `superadmin`, pero no comprueba si disponen de alcance global y pueden usar las acciones administrativas. Además, la reducción del alcance global no comprueba ese mismo invariante. Las escrituras administrativas requieren cuenta activa, permiso y alcance global, no solo el nombre del rol.

**Dos reproducciones:**

- Crear mediante la API otro usuario activo con rol `superadmin`. El alta lo deja con alcance global desactivado. Desactivar después al administrador global original se permite con **302** porque existe ese segundo usuario por rol, pero quedan **cero administradores operativos**.
- Con un único administrador global y concesiones propias ya existentes, enviar esas mismas listas y reducir su `scope_all` a false. La operación se guarda con **302**, sin ampliar concesiones propias ni disparar el bloqueo por listas vacías de ERR-064. Quedan **cero administradores operativos** y una escritura posterior de roles se rechaza con **403**.

El caso ordinario de desactivar al único superadmin sin otro candidato sí se bloquea. El fallo aparece al confundir el rol con la capacidad efectiva y al omitir la vía de pérdida de alcance. No se afirma que los datos se pierdan irreversiblemente: la recuperación podría requerir intervención fuera de la administración normal de la aplicación.

**Evidencia:** `ultimo-admin-candidato-no-global.json`, `ultimo-admin-pierde-global.json` y sus dos pruebas identificadas en `resultados.xml`.

**Corrección propuesta:** centralizar la protección de la capacidad administrativa efectiva y aplicarla a cambios de actividad, roles, permisos y alcance. Verificar dentro de la transacción que queda una vía administrativa autorizada y operativa, con un orden de bloqueo consistente. No considerar automáticamente sustituto a un rol que carece del alcance necesario.

## ERR-066 — El cambio de ámbito utiliza un actor desactualizado después de su revocación

- **Estado:** confirmado con intercalación controlada en la ruta HTTP, pendiente. **Prioridad:** alta para la revalidación de escrituras de acceso.
- **Ubicación:** `app/Services/Security/UpdateUserScope.php:16`–`:19`, en contraste con la recarga del actor en las otras acciones de acceso.

La acción comprueba el alcance y permiso sobre el objeto de actor recibido, antes de comenzar su transacción. Dentro de ella solo vuelve a cargar y bloquear al destinatario. Si cambia el estado del actor después de que la solicitud cargue su usuario, la escritura puede seguir autorizándose con los datos anteriores.

**Reproducción HTTP controlada:** iniciar un cambio de ámbito con administrador activo y confirmación vigente. Al resolver la acción, después de la autorización de la solicitud, simular la desactivación de ese actor en la base de datos. Existe otro administrador global activo, por lo que la revocación representada no dejaría sin administración al sistema. La petición todavía devuelve **302** y concede al destinatario **alcance global**, aunque el actor ya figura **inactivo** en la base.

Se utilizó un callback del contenedor de pruebas para insertar ese cambio de estado en una ventana determinista. No se ejecutaron dos conexiones concurrentes reales ni se afirma que una nueva petición iniciada después de la desactivación eluda el middleware: el caso de cuenta desactivada sigue bloqueándose normalmente. La reproducción demuestra la falta de revalidación de una operación que ya estaba en curso.

**Evidencia:** `test_cambio_de_ambito_revalida_actor_revocado_despues_de_autorizar` y `actor-revocado-entre-autorizacion-y-escritura.json`.

**Corrección propuesta:** recargar y bloquear al actor en la transacción y revalidar su actividad, permiso y alcance antes de guardar. Coordinar esa comprobación con las operaciones de revocación, respetando un orden de bloqueo consistente. No depender del estado o de las relaciones de permisos ya cargadas en el objeto de la solicitud.

## ERR-067 — El alta admite mayúsculas que el login no puede recuperar en el SQLite configurado

- **Estado:** confirmado en SQLite, pendiente. **Prioridad:** alta para utilizar cuentas creadas válidamente.
- **Ubicación:** `app/Http/Requests/StoreUserRequest.php:18`; `app/Actions/Access/StoreUser.php:16`–`:18`; `app/Http/Requests/Auth/LoginRequest.php:45`–`:48`.

El alta permite y conserva letras mayúsculas en el nombre de usuario. El login convierte siempre el identificador a minúsculas antes de consultarlo. Con la comparación de texto del SQLite probado, ese valor no coincide con el almacenado, aunque se envíe el nombre exacto que el administrador creó y la contraseña correcta.

**Reproducción HTTP:** crear `Operador_Mayusculas` mediante el alta administrativa. La operación redirige sin errores y conserva ese texto. Cerrar la autenticación del administrador e intentar iniciar sesión con ese mismo nombre y su contraseña válida falla: se busca **`operador_mayusculas`**, no se autentica y se devuelve el error de credenciales.

El entorno local declara `DB_CONNECTION=sqlite` y la prueba usa SQLite en memoria. No se extrapola el resultado a una instalación MySQL con otra intercalación de texto.

**Evidencia:** `test_usuario_con_mayusculas_creado_por_api_puede_autenticarse_en_sqlite` y `login-mayusculas-sqlite.json`.

**Corrección propuesta:** definir una representación y política de comparación consistentes para los identificadores de acceso, aplicándolas en alta, edición, validación de unicidad y login. Revisar posibles colisiones entre cuentas existentes antes de normalizar datos. El formato mostrado puede conservarse por separado si se necesita respetar su presentación.

## ERR-068 — Recuperar la contraseña no invalida una sesión anterior con el controlador de archivos

- **Estado:** confirmado con driver `file`, pendiente. **Prioridad:** alta para la recuperación de una cuenta comprometida.
- **Ubicación:** `app/Http/Controllers/Auth/NewPasswordController.php:48`–`:59`; composición de middleware en `bootstrap/app.php:24`–`:31`.
- **Entorno:** `.env` declara `SESSION_DRIVER=file`; la prueba reproduce ese controlador en un directorio exclusivo, no en el almacén operativo.

La recuperación cambia la contraseña y el token de recuerdo, pero solo elimina otras sesiones si el controlador es `database`. La regeneración de la sesión de la petición de recuperación no invalida las cookies de sesiones anteriores almacenadas en archivos. La pila web revisada tampoco añade una comprobación de cambio de hash de contraseña a cada sesión autenticada.

**Reproducción HTTP con cookies:**

1. Iniciar sesión con un usuario sintético y comprobar que su cookie abre el panel.
2. Crear un token de recuperación mediante el broker de pruebas, sin enviar correo, y completar el POST de recuperación desde una sesión de invitado. Se comprueba que la nueva contraseña sí queda guardada.
3. Reutilizar la cookie anterior en un contexto web recargado. El archivo de esa sesión sigue existiendo con el contenido anterior y el panel responde **200**, autenticando al mismo usuario **sin volver a presentar ninguna contraseña**.

Los archivos de esta prueba se borran al terminar. El caso conforme de restablecimiento administrativo con driver `database` sí elimina las sesiones objetivo. Esta incidencia no afirma que se omita el bloqueo de cuentas inactivas; se refiere a una cuenta activa cuya contraseña se acaba de recuperar.

**Evidencia:** `test_recuperar_clave_invalida_sesion_anterior_con_driver_file` y `sesion-file-tras-recuperacion.json`. No se guardaron cookies, tokens de recuperación ni contraseñas en el JSON.

**Corrección propuesta:** implementar revocación de sesiones o una comprobación de versión de credencial que funcione con los controladores admitidos, verificándola en cada petición autenticada. Invalidar las sesiones anteriores al recuperar la cuenta, conservar únicamente la sesión que corresponda según el flujo y mantener la rotación del token de recuerdo. No depender exclusivamente de borrar filas de `sessions` cuando se permite usar archivos.

## ERR-069 — Se aceptan contraseñas distintas cuyo contenido después del byte 72 no participa en el cotejo bcrypt

- **Estado:** confirmado en el runtime probado con bcrypt, pendiente. **Prioridad:** alta para que la credencial validada coincida con la introducida.
- **Ubicación:** `app/Http/Requests/StoreUserRequest.php:20`; `app/Http/Requests/Auth/LoginRequest.php:32`; cast `hashed` de `app/Models/User.php`; adaptador instalado en `vendor/laravel/framework/src/Illuminate/Hashing/BcryptHasher.php:55`–`:92`.

El alta y el login permiten contraseñas de hasta 255 caracteres. En el entorno probado, el algoritmo es bcrypt y no tiene límite de entrada configurado. Se admiten entradas más largas que la porción que participa en su comparación, por lo que el sistema no distingue contraseñas que difieren únicamente después de ese límite.

**Reproducción HTTP:** crear por el alta administrativa una contraseña sintética de **84 bytes**. Preparar otra entrada distinta, también de 84 bytes, con los **primeros 72 idénticos** y un sufijo diferente. El cotejo con el hash guardado devuelve **true** y el login con esa segunda entrada **también autentica**. Como control, modificar el byte 72 sí se rechaza.

No se afirma que cualquier contraseña incorrecta funcione: esta reproducción requiere compartir ese prefijo. Se verificó con PHP 8.2.12 y el bcrypt configurado en la prueba; no se atribuye el mismo comportamiento a otros algoritmos ni a una configuración que rechace previamente esas entradas. La evidencia solo guarda longitudes y resultados booleanos, sin contraseñas ni hashes.

**Evidencia:** `test_clave_distinta_despues_del_byte_72_no_autentica` y `clave-larga-cotejo.json`, además del hash del adaptador instalado registrado en la navegación.

**Corrección propuesta:** hacer consistente la política de longitud de entrada con el algoritmo elegido en alta, edición, recuperación y login. Si se mantiene bcrypt, validar el límite admitido en **bytes**, no solo en caracteres, antes de hashear y comunicarlo al usuario. Otra opción es una migración controlada a un hasher estándar admitido por el framework que compare la entrada completa. No introducir transformaciones criptográficas propias ni cambiar hashes existentes sin un plan de transición.

### Límites del undécimo bloque

Se verificaron rutas HTTP de administración y autenticación con SQLite en memoria, cookies y un directorio de sesiones de archivos aislado. No se cambiaron cuentas ni sesiones operativas, no se enviaron correos de recuperación y no hubo comunicaciones externas. No se realizó una revisión visual completa de la pantalla administrativa. La revocación intercalada de ERR-066 es una simulación determinista, no una prueba de concurrencia real en MySQL. ERR-067 se limita al SQLite probado; ERR-068 al controlador de archivos y ERR-069 al runtime bcrypt descrito.

No se modificaron archivos de aplicación, dependencias, configuración persistente ni los Excel originales. Este bloque suma **siete incidencias nuevas: 69 incidencias confirmadas y pendientes en total**. El siguiente módulo propuesto es configuración del sistema y copias de seguridad, revisando su comportamiento con datos aislados y sin extraer información de la base operativa.

## Auditoría del duodécimo módulo: configuración y copias de seguridad

Fecha: **2026-10-29**, America/La_Paz. Se siguieron los nodos de configuración, branding y respaldos del árbol existente de Graphify, y sus conexiones hacia almacenamiento, permisos y ciclo financiero. Se acotaron **40 archivos indexados**, con **303 nodos y 489 enlaces** en el recorte. Sus tiempos de modificación coinciden con el manifiesto. Se registraron además cinco archivos de contexto de arranque, configuración y documentación, distinguiéndolos de ese recorte. La navegación y los hashes están en `verification/settings-backup-audit/navegacion-graphify.json`.

Los Excel no definen permisos de respaldo, cifrado ni caché del logo. Para esos aspectos se comparó la pantalla con las rutas y los servicios correspondientes. Las reglas financieras configurables se contrastaron con el ciclo financiero vigente; no se dedujeron nuevas instrucciones de los documentos ni se declaró equivalencia completa con los libros.

**Resultado:** cinco incidencias nuevas, ERR-070 a ERR-074. Las doce pruebas aisladas suman **124 aserciones: seis pruebas conformes y seis fallos deliberados de contrato**, sin errores de ejecución de PHPUnit. Dos de los fallos documentan las dos opciones desconectadas agrupadas en ERR-073. La suite existente relacionada pasa: **17 pruebas, 84 aserciones**.

### Controles conformes y evidencia

- Solo visualizar respaldos no permite generarlos. La administración sin alcance global tampoco permite abrir ni generar respaldos.
- Un usuario sin `settings.manage` no cambia la configuración. Los colores válidos se normalizan y auditan; un color inválido no altera el valor ni añade una auditoría de actualización.
- El respaldo de SQL **sintético** se cifra, guarda como privado y verifica por tamaño y SHA-256. La recuperación de más de dos bloques reproduce exactamente la entrada, incluye la marca final autenticada y una alteración de la trama cifrada se rechaza.
- La falta de clave independiente produce un fallo controlado, sin archivo publicado, y elimina el temporal de entrada. En el caso conforme también se eliminan los temporales de entrada y cifrado.
- Con ambos permisos, la descarga entrega el cifrado correcto, con `no-store` y `nosniff`. El bloqueo normal al aprobar una factura sigue funcionando.

Pruebas y resultados: `verification/settings-backup-audit/ConfiguracionRespaldosFlujoAuditTest.php`, `resultados.xml`, `salida-pruebas.txt` y `pruebas-existentes.txt`. Los JSON del mismo directorio contienen los resultados particulares; no guardan claves criptográficas, contraseñas ni volcados operativos.

## ERR-070 — Administrar respaldos permite generarlos, pero su descarga exige otro permiso

- **Estado:** confirmado por HTTP, pendiente. **Prioridad:** media.
- **Ubicación:** `routes/web.php:167`–`:169`; `app/Http/Controllers/BackupController.php:46`.

Las rutas de listado y descarga admiten `backups.view` **o** `backups.manage`. La generación exige administrar. Sin embargo, el controlador de descarga exige específicamente visualizar. La interfaz muestra el enlace de descarga para todo respaldo completado sin explicar esta diferencia.

**Reproducción:** usuario sintético activo, con alcance global, confirmación vigente de contraseña y **solo** `backups.manage`. Puede abrir el módulo, generar un respaldo cifrado con el escritor de prueba y obtener una ejecución `completed`. Al seguir su enlace de descarga recibe **403**. El mismo flujo con ambos permisos entrega el archivo correctamente.

La prueba existente de descarga añade automáticamente `backups.view` cuando solicita `backups.manage`, por lo que no cubre esta combinación válida para las rutas. No se propone ampliar acceso a usuarios sin alcance global ni saltar la confirmación de contraseña.

**Evidencia:** `permiso-administrar-descarga.json` y `test_administrar_respaldo_permite_descargarlo_segun_la_ruta`.

**Corrección propuesta:** unificar el contrato del permiso en ruta, controlador e interfaz. Si administrar incluye descargar, comprobar la misma alternativa de permisos. Si se decide exigir visualizar por separado, reflejarlo en la ruta y ocultar o explicar esa acción al administrador que carece del permiso. Mantener las restantes restricciones de acceso.

## ERR-071 — Se ofrece generar respaldos en el SQLite local, pero el único escritor admite MySQL

- **Estado:** confirmado con el motor configurado localmente, pendiente. **Prioridad:** media, condicionada al uso de SQLite.
- **Ubicación:** enlace del escritor en `app/Providers/AppServiceProvider.php:27`–`:28`; rechazo en `app/Services/Backup/MySqlSnapshotWriter.php:17`–`:19`; botón de generación en `resources/js/Pages/Backups/Index.jsx`.

El entorno local declara `DB_CONNECTION=sqlite`. La pantalla permite generar un respaldo sin advertencia preventiva de incompatibilidad, pero el contrato `DatabaseSnapshotWriter` siempre se resuelve a `MySqlSnapshotWriter`, que rechaza ese motor. El README declara MySQL como parte del stack: la incidencia es la discrepancia de la instalación local y la capacidad que muestra su interfaz, **no un defecto del rechazo preventivo del escritor ni una afirmación sobre instalaciones MySQL**.

**Reproducción segura:** conservar el enlace real del contenedor y usar exclusivamente SQLite **en memoria**. El módulo abre con 200, pero el POST de generación termina en una ejecución **`failed`**, redirige con una referencia de error y no publica ningún archivo. La guardia del escritor actúa antes de lanzar `mysqldump`. Las pruebas existentes sustituyen el escritor por uno que crea SQL sintético; validan el servicio de cifrado y publicación, pero no esta compatibilidad.

**Evidencia:** `escritor-real-sqlite.json` y `test_generacion_ofrecida_funciona_con_el_sqlite_configurado`. La variable local observada se limita a la elección del motor; no se extrajeron credenciales ni datos de la base operativa.

**Corrección propuesta:** decidir y comunicar los motores admitidos. Si el despliegue debe usar MySQL, comprobarlo al preparar el entorno y mostrar la incompatibilidad antes de ofrecer la acción. Si se admite SQLite, implementar y verificar un escritor transaccional apropiado para ese motor. Mantener el rechazo seguro cuando no exista un escritor compatible.

## ERR-072 — El aviso de un respaldo fallido puede sustituir la respuesta controlada por un error 500

- **Estado:** confirmado por HTTP con dos fallos simulados, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Services/DatabaseBackupService.php:85`–`:99`, especialmente la llamada de la línea 93.

El servicio protege el aviso de éxito para que su fallo no invalide un respaldo ya verificado. En cambio, cuando falla la generación, registra `failed` y llama al aviso de error sin capturar una segunda excepción. Si también falla ese aviso, la excepción sale del servicio y el controlador no puede presentar el mensaje de error con su referencia.

**Reproducción HTTP:** un escritor de prueba lanza una excepción antes de crear cualquier archivo; el canal de notificaciones simulado también lanza una excepción. La ejecución queda correctamente registrada como **`failed`**, no hay archivo publicado y el bloqueo se libera, pero el POST responde **500**, en lugar de la redirección con error de respaldo que implementa el controlador.

Esta incidencia no implica que se anuncie un respaldo correcto ni que el bloqueo quede retenido. Se limita al manejo de la segunda excepción y a la respuesta al usuario. El control conforme sin clave criptográfica, pero con aviso disponible, sí devuelve un fallo controlado.

**Evidencia:** `aviso-tras-fallo.json` y `test_aviso_fallido_no_rompe_respuesta_del_respaldo_fallido`.

**Corrección propuesta:** aislar también el aviso de fallo y registrar su excepción conservando el resultado principal y su referencia. Un canal secundario no debe impedir que el controlador o el comando comuniquen el estado de generación ya guardado.

## ERR-073 — Las dos reglas financieras de configuración se guardan sin aplicar lo que anuncia la pantalla

- **Estado:** confirmado por dos recorridos HTTP, pendiente. **Prioridad:** alta para la fidelidad de la configuración ofrecida.
- **Ubicación:** `resources/js/Pages/SystemSettings/Edit.jsx:113`–`:122`; `resources/js/Pages/Invoices/Index.jsx:185`–`:187`; `app/Services/Finance/InvoiceLifecycleService.php:65` y `:181`; `app/Http/Controllers/AccountingController.php:35`.

Se agrupan las dos manifestaciones del contrato de reglas expuestas por la misma pantalla de configuración:

1. **Proteger el Final al crearlo:** activar y guardar la opción, crear un Final de un caso `final_only` y modificar su matriz. El nuevo documento queda **`draft`**, con `locked_at = null`; el refresco permite cambiar su total de **330,00 a 580,00** y devuelve 302 sin errores. Tanto configuración como la pantalla de emisión anuncian que quedará protegido al crearse. Como control, aprobar después el documento con otro actor autorizado sí establece el bloqueo: el ciclo de aprobación funciona, pero la opción anunciada no gobierna la creación.
2. **Contabilidad puede corregir resumen:** guardar `accounting_can_edit_summary = true` y abrir contabilidad con permisos de visualizar y administrar. La base conserva **true**, pero el controlador envía siempre **`settings.accountingCanEditSummary = false`**. El destino no refleja lo elegido por el administrador. No se interpreta que este ajuste permita sobrescribir importes de facturas aprobadas ni se propone reabrir esa vía obsoleta.

**Evidencia:** `regla-proteccion-final.json`, `regla-contabilidad.json` y sus dos pruebas en `resultados.xml`.

**Corrección propuesta:** alinear las opciones con el ciclo financiero vigente y definir qué comportamiento admitido controla cada una. Si esas opciones quedaron obsoletas, retirarlas o explicar su sustitución, junto con los avisos contradictorios. Si deben seguir activas, integrarlas de forma explícita y verificable. Conservar siempre la revisión, aprobación y trazabilidad de documentos; no resolver esta discrepancia debilitando la inmutabilidad de facturas aprobadas.

## ERR-074 — Reemplazar el logo en el mismo segundo reutiliza una URL declarada inmutable durante un año

- **Estado:** confirmado mediante cargas y respuestas HTTP, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Services/BrandingAssetService.php:43`; `app/Http/Controllers/BrandingLogoController.php:19`.

La versión de la URL del logo utiliza solo `updated_at` en segundos. El endpoint devuelve `Cache-Control: public, max-age=31536000, immutable`. Dos archivos nuevos, cargados en ese mismo segundo, reciben el mismo parámetro `v` aunque tengan IDs y contenido distintos.

**Reproducción:** fijar el reloj de pruebas en un segundo y cargar, mediante la configuración real, dos PNG válidos distintos. Las dos cargas pasan la validación y apuntan a archivos diferentes, con checksums y ETags diferentes. Sin embargo, la URL anterior y la nueva son **idénticas** y conservan el encabezado de caché inmutable de un año. Un cliente que ya haya almacenado la primera respuesta puede seguir mostrando el logo anterior sin solicitar el segundo.

La limpieza de caché interna de configuración no cambia esa URL. Un ETag distinto tampoco fuerza por sí solo una petición desde un cliente que conserva una respuesta fresca e inmutable. Se verificaron las cargas y cabeceras HTTP; no se simuló un año de caché en un navegador real.

**Evidencia:** `logo-version-repetida.json` y `test_sustituir_logo_en_mismo_segundo_cambia_url_inmutable`.

**Corrección propuesta:** versionar el recurso con una identidad que cambie al cambiar su contenido, como su checksum o un identificador de archivo apropiado. Mantener coherencia entre esa identidad, el contenido servido y la política de caché. No depender únicamente de la precisión de segundos.

### Límites del duodécimo bloque

Se usaron datos sintéticos, SQLite en memoria, almacenamiento simulado y temporales de prueba. El recorrido con escritor real se detuvo en su rechazo de SQLite: **no se ejecutó `mysqldump`, no se extrajo la base operativa ni se hizo una restauración sobre ella**. La recuperación comprobada corresponde únicamente al cifrado de la fixture sintética. No se certifica aquí una restauración integral MySQL ni el funcionamiento real de R2.

No se enviaron comunicaciones externas ni se alteraron cuentas operativas. No se modificaron código de aplicación, dependencias, configuración persistente ni los Excel originales. Los hashes de fuentes revisadas y de ambos libros se vuelven a verificar en `verification/settings-backup-audit/cerrar_auditoria.py`, con resultados en `resumen.json`.

Este bloque añade **cinco incidencias: 74 incidencias confirmadas y pendientes en total**. El siguiente módulo propuesto es **auditoría y trazabilidad de cambios**, partiendo de `AuditLogController` y sus vecinos en el árbol de nodos.

## Auditoría del decimotercer módulo: historial y trazabilidad de cambios

Fecha: **2026-10-29**, America/La_Paz. La revisión parte de `AuditLogController`, `AuditLogQuery`, `AuditLog` y `AuditWriter`, siguiendo sus vecinos hacia los productores de eventos financieros, proyectos y recibos. El recorte del árbol de Graphify contiene **47 archivos indexados, 300 nodos y 452 enlaces**. Los tiempos de modificación de los archivos coinciden con el manifiesto. Se registraron además `phpunit.xml` y `bootstrap/app.php` como contexto. Navegación, enlaces y hashes: `verification/audit-trail-audit/navegacion-graphify.json`.

Los Excel sirven de referencia para los datos del flujo financiero, pero no especifican cómo debe paginarse ni conservarse un historial técnico. En este módulo se contrastó la función anunciada de «Historial de cambios» con las mutaciones reales y los permisos existentes. No se trataron textos de los documentos como instrucciones del usuario ni se afirmó equivalencia completa con los libros.

**Resultado:** seis incidencias nuevas, ERR-075 a ERR-080, y una propagación de ERR-039 sin aumentar su conteo. Las pruebas aisladas suman **13 casos y 86 aserciones: cinco casos conformes y ocho fallos deliberados de contrato**, con cero errores de ejecución de PHPUnit. Dos fallos corresponden a distintos productores de eventos afectados por ERR-076; otro reproduce ERR-039. La suite existente relacionada pasa: **7 casos, 18 aserciones**.

### Controles conformes y evidencia

- El invitado se redirige al login y el usuario sin `audit_logs.view` recibe 403.
- Con eventos correctamente vinculados, el revisor de un proyecto ve su fase y sus eventos propios sin fase; quedan fuera las fases de otro proyecto y los eventos globales de otros actores. Tampoco se filtra el módulo ajeno en las opciones de filtros.
- Sin filtros, **60 eventos con la misma fecha** se recuperan en páginas de **25, 25 y 10**, sin pérdidas ni IDs duplicados. La consulta ordena por fecha e ID, por lo que no repite el defecto de ordenación registrado para otro módulo.
- Un cambio real de matriz conserva en su evento el mismo identificador `X-Request-ID` devuelto por HTTP. Un intento posterior con versión obsoleta responde 409, sin cambiar el importe ni añadir un evento de éxito.
- Un fallo simulado al guardar el evento revierte la modificación financiera y su incremento de versión: no queda una fila de matriz confirmada sin su auditoría.

Prueba reproducible: `verification/audit-trail-audit/AuditoriaTrazabilidadFlujoAuditTest.php`. Resultados: `resultados.xml`, `salida-pruebas.txt` y `pruebas-existentes.txt`, en ese mismo directorio. Los JSON conservan resultados sintéticos por recorrido.

## ERR-075 — Paginar el historial descarta los filtros y mezcla eventos que no coinciden

- **Estado:** confirmado siguiendo el enlace HTTP real de la página siguiente, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Queries/AuditLogQuery.php:16`–`:20`; enlaces de `resources/js/Pages/AuditLogs/Index.jsx`.

La primera consulta aplica módulo, acción y usuario, pero el paginador no conserva esos parámetros en sus URLs. La interfaz sigue directamente `next_page_url` y `prev_page_url`, por lo que la siguiente consulta conserva solo el cursor.

**Reproducción:** crear treinta eventos objetivo de un usuario y treinta eventos intercalados de otro módulo, acción y usuario, con la misma fecha. Filtrar por los tres campos. La primera página devuelve los veinticinco eventos correctos, pero su enlace siguiente solo contiene `cursor`. Al abrirlo se reciben diez eventos mezclados; los tres filtros llegan vacíos y aparecen acciones excluidas en la primera consulta. Debían quedar cinco eventos objetivo.

El ámbito del actor se sigue aplicando. Esta incidencia muestra resultados incorrectos dentro de lo autorizado; no demuestra acceso a eventos de otros ámbitos. El control sin filtros sí recupera los sesenta eventos correctamente.

**Evidencia:** `filtros-perdidos-en-cursor.json` y `test_siguiente_pagina_conserva_modulo_accion_y_usuario`.

**Corrección propuesta:** propagar los filtros validados a ambos enlaces del cursor y mantenerlos al navegar. Reiniciar el cursor al aplicar o limpiar filtros. Mantener el orden estable por fecha e ID.

## ERR-076 — Cambios del expediente, matriz y contabilidad quedan invisibles para otros revisores del mismo ámbito

- **Estado:** confirmado mediante mutaciones HTTP y consultas del historial, pendiente. **Prioridad:** alta para la supervisión financiera.
- **Ubicación:** `app/Queries/AuditLogQuery.php:38`–`:44`; `app/Services/Finance/BillingCaseService.php:32`–`:36`; `app/Services/Finance/BudgetService.php:76`–`:80`; `app/Services/Finance/AccountingReviewService.php:46`–`:50`.

La consulta de visibilidad de un actor sin alcance global usa la columna `audit_logs.trip_phase_id`. Si es nula, solo permite al propio autor ver el evento. Varios productores no rellenan esa columna: algunos incluyen el ID de fase dentro de `metadata`, que la consulta no utiliza; la revisión contable tampoco guarda allí el contexto necesario para la consulta actual.

**Dos reproducciones con actores diferentes:**

1. Un actor autorizado crea un expediente MAT y guarda su matriz. Ambos cambios se confirman y generan `billing_case_created` y `budget_matrix_updated`, pero los dos eventos tienen **`trip_phase_id = null`**. Su autor global ve los dos. Otro usuario con `audit_logs.view` y concesión explícita sobre ese mismo proyecto recibe **cero eventos**.
2. Un actor autorizado guarda una revisión contable con importe 583,00, cuyo `source.case_ids` contiene únicamente un caso de la fase concedida al revisor. El cambio queda guardado, pero su evento también tiene **fase nula** y no aparece al otro revisor autorizado.

El control de eventos con la columna correctamente rellenada sí respeta el ámbito y muestra los eventos permitidos. No se propone eliminar la restricción para eventos sin fase: eso podría exponer auditoría global o de otros proyectos.

**Evidencia:** `eventos-matriz-sin-fase.json`, `revision-contable-sin-fase.json` y sus dos pruebas en `resultados.xml`.

**Corrección propuesta:** persistir el contexto de visibilidad de forma consistente al generar eventos y resolverlo en una consulta compartida. Para expedientes y matrices, conservar su fase en la columna correspondiente. Para revisiones que agregan varios casos, definir un contexto que exija visibilidad sobre todos los casos afectados; no asignar sin más la primera fase y exponer datos agregados de otras. Planificar una corrección controlada del contexto de eventos históricos sin cambiar sus hechos ni abrir indiscriminadamente los eventos sin fase.

## ERR-077 — El historial de matriz omite los valores sustituidos y no permite reconstruir una captura anterior

- **Estado:** confirmado con dos cambios reales de matriz, pendiente. **Prioridad:** alta para la trazabilidad de importes.
- **Ubicación:** `app/Services/Finance/BudgetService.php:45`–`:62` y `:76`–`:80`.

Al guardar una matriz se actualiza la fila de esa etapa con los nuevos valores. El evento de auditoría conserva fase en metadata, etapa, versión del expediente e IDs de líneas, pero no registra cantidades, costos, recibos ni valores anteriores y posteriores de esas líneas.

**Reproducción:** capturar en una línea MAT un costo de **11,2500** y guardarlo de nuevo con **19,7500**. Ambos PATCH terminan correctamente y producen dos eventos con versiones consecutivas. Solo queda **una fila de versión de etapa**, con 19,7500, y ninguno de los dos eventos entregados al historial contiene 11,2500. La captura anterior no se puede recuperar a partir de estos registros.

La prueba se realiza antes de emitir o aprobar una factura. No cuestiona la inmutabilidad de snapshots aprobados ni afirma que haya cambiado un documento emitido. La pérdida afecta al historial de preparación de la matriz, incluso al consultarlo con alcance global; por eso es distinta del problema de visibilidad de ERR-076.

**Evidencia:** `valores-matriz-no-retenidos.json` y `test_historial_de_matriz_conserva_los_importes_sustituidos`.

**Corrección propuesta:** guardar un diff con los campos financieros permitidos, sus valores anteriores y nuevos y la identidad de cada línea/celda, o conservar revisiones inmutables equivalentes. Hacerlo en la misma transacción, manteniendo correlación, actor, etapa y versión; no limitar el evento a señalar qué IDs se tocaron.

## ERR-078 — Actualizar un proyecto genera un evento sin sus cambios y con una versión constante

- **Estado:** confirmado por HTTP, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Actions/Projects/SaveProject.php:29`–`:32`; campos admitidos por defecto en `app/Services/Security/AuditWriter.php:26`–`:29`.

La acción guarda el proyecto y llama al escritor de auditoría con `before = []` y `after = ['version' => 1]`, tanto para crear como para actualizar. No entrega al escritor el código, nombre u otros campos modificados. Además, el conjunto de campos permitido por defecto para esa acción tampoco contempla esos datos.

**Reproducción HTTP:** cambiar el código y nombre de un proyecto existente por `TRAZA-NUEVA` y `Proyecto renombrado en prueba`. La actualización se confirma y ambos valores cambian en la base, pero el evento `project_saved` solo contiene **`before: []`** y **`after: {version: "1"}`**. El historial identifica quién guardó y qué entidad, pero no qué datos cambió. Ese 1 no representa una revisión incremental del proyecto.

No se atribuye el fallo únicamente a la sanitización: la acción ya omite los valores antes de llegar a ella. Deben corregirse ambos contratos si se quieren incorporar los cambios permitidos.

**Evidencia:** `proyecto-detalle-vacio.json` y `test_historial_de_proyecto_conserva_codigo_y_nombre_antes_y_despues`.

**Corrección propuesta:** capturar explícitamente los campos autorizados antes y después de guardar, y declarar su lista específica en el escritor. Diferenciar alta y modificación y usar una revisión real si se presenta una versión. No guardar indiscriminadamente atributos ni secretos.

## ERR-079 — Cambiar la fecha de un recibo convierte ambas fechas del historial en null

- **Estado:** confirmado mediante edición HTTP de recibo, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Actions/Receipts/UpdateReceipt.php:44` y `:57`–`:60`; `app/Services/Security/AuditWriter.php:27`–`:29`; cast de fecha en `app/Models/Receipt.php:13`.

La acción obtiene `issued_on` mediante `only()` de un modelo con cast a fecha, por lo que entrega objetos de fecha al escritor. Este solo conserva escalares o null; convierte cualquier objeto en null. El campo sí está permitido para `receipt_updated`, pero sus valores no se normalizan antes de sanitizarse.

**Reproducción:** editar un recibo cuya fecha es **2025-07-10** y cambiarla a **2025-07-11**, manteniendo su importe 160,00. La petición se guarda correctamente y el modelo conserva la nueva fecha. El evento tiene la fase correcta y mantiene el importe, pero en metadata aparecen **`before.issued_on = null`** y **`after.issued_on = null`**. El detalle no permite comprobar qué fecha se corrigió.

No es un problema de formato visual ni de ámbito: los valores ya están perdidos en el evento guardado. La fecha del recibo en la base sí se actualiza correctamente.

**Evidencia:** `fecha-recibo-perdida.json` y `test_cambiar_fecha_de_recibo_retiene_fecha_anterior_y_nueva`.

**Corrección propuesta:** normalizar las fechas permitidas a su representación documental antes de sanitizar, distinguiendo una fecha real de un valor nulo. Mantener la lista de campos y la exclusión de objetos o secretos no admitidos; no serializar objetos arbitrarios para resolver este caso.

## ERR-080 — Eliminar un proyecto no deja un evento de eliminación en el historial

- **Estado:** confirmado por HTTP sobre un proyecto sintético sin dependencias, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Http/Controllers/ProjectController.php:61`–`:72`.

El alta y la edición pasan por una acción que escribe auditoría, aunque con el detalle insuficiente descrito en ERR-078. La eliminación llama directamente a `delete()` y devuelve éxito sin registrar su propio evento.

**Reproducción:** crear mediante la API un proyecto sin fases ni otras dependencias. El alta deja un evento `project_saved`. Eliminarlo con el permiso correspondiente: el DELETE redirige sin errores y el proyecto desaparece de la base, pero el conteo de eventos sigue en **uno** y la única acción conservada sigue siendo `project_saved`. No queda un evento que identifique quién lo eliminó, cuándo y con qué correlación.

La prueba utiliza un proyecto eliminable válidamente; no elude ni cuestiona las restricciones que impiden borrar proyectos con dependencias. Esta ausencia de evento es distinta de omitir detalles en un evento de actualización que sí existe.

**Evidencia:** `eliminacion-proyecto-sin-evento.json` y `test_eliminar_proyecto_registra_su_eliminacion`.

**Corrección propuesta:** realizar la eliminación autorizada y su evento en una misma transacción, conservando la identidad y los datos permitidos del proyecto antes de borrarlo, además del actor y correlación. Mantener las restricciones referenciales y no registrar éxito si la eliminación se rechaza.

### Propagación de ERR-039 al selector de autores del historial

`AuditLogQuery.php:28` entrega solo los primeros veinticinco usuarios con eventos visibles, ordenados por nombre, sin búsqueda remota ni otra página del selector. Con veintiséis autores, el último queda fuera, aunque consultar directamente su ID sí devuelve su evento autorizado. La pantalla construye sus opciones únicamente con esa lista. Se confirma con `propagacion-ERR-039-selector-auditoria.json` y la prueba del usuario número 26. Es otra manifestación del problema de selectores ya registrado: **no se crea una incidencia adicional**.

### Límites del decimotercer bloque

Todos los actores, proyectos, importes, recibos y eventos utilizados son sintéticos y se guardan en **SQLite en memoria**. No se modificó, eliminó ni consultó el historial operativo; no se enviaron mensajes, no se procesaron importaciones reales ni se alteraron los Excel originales. El recibo utilizado como fixture no se descargó ni requirió publicar un archivo. No se hizo una revisión visual completa del navegador ni una prueba de concurrencia real MySQL.

Se conservaron el código de aplicación, dependencias y configuración persistente. La comprobación final de hashes de las fuentes revisadas y de ambos libros, los identificadores consecutivos y los resultados queda en `verification/audit-trail-audit/cerrar_auditoria.py` y `resumen.json`.

Este bloque añade **seis incidencias nuevas: 80 incidencias confirmadas y pendientes en total**. El siguiente módulo propuesto es **catálogos**, partiendo de `CatalogController` y `CatalogService` en el árbol de nodos.

## Auditoría del decimocuarto módulo: catálogos

Fecha: **2026-10-29**, America/La_Paz. Se partió de `CatalogController` y `CatalogService`, siguiendo los nodos de comunidades y categorías hacia proyectos, capítulos y gastos. El recorte de Graphify comprende **34 archivos indexados, 293 nodos y 408 enlaces**, cuyos tiempos de modificación coinciden con el manifiesto. Se registraron además dos archivos de contexto de arranque y pruebas. Navegación y hashes: `verification/catalogs-audit/navegacion-graphify.json`.

Se revisaron los cinco catálogos: tipos de capítulo, países, comunidades, universidades y categorías de gasto. El catálogo editable de categorías es **heredado**: el seeder financiero y las pruebas del catálogo vigente distinguen ese almacenamiento del `BudgetCatalog` fijo que usa la matriz. No se interpretó su independencia como un fallo ni se propuso hacer variables las reglas de los Excel. Los documentos no se trataron como instrucciones del usuario.

**Resultado:** cinco incidencias nuevas, ERR-081 a ERR-085. Cuatro tienen reproducción funcional; ERR-085 se constata mediante inspección estática de la interfaz y verificación HTTP de los permisos. La falta de eventos en las escrituras de catálogos se documenta como ampliación del problema de trazabilidad de ERR-080, **sin sumar otra incidencia**.

Las pruebas aisladas suman **13 casos y 159 aserciones: ocho casos conformes y cinco fallos deliberados de contrato**, con cero errores de ejecución de PHPUnit. Los cinco fallos corresponden a los cuatro defectos funcionales nuevos y a la ampliación de trazabilidad; no se presenta el defecto visual de permisos como una prueba de navegador. La suite existente relacionada pasa: **25 casos, 69 aserciones**.

### Controles conformes y evidencia

- El administrador global completa alta, listado, edición y eliminación de registros sin dependencias en los cinco catálogos.
- El lector puede consultar, pero los tres métodos de escritura se rechazan con 403. Administrar sin alcance global tampoco permite guardar. Una cuenta inactiva se cierra y redirige al login. La revocación de alcance intercalada después de autorizar la solicitud se vuelve a comprobar dentro del servicio y evita el alta.
- La unicidad de comunidad se aplica por país: se admite un nombre homónimo en dos países, y se rechaza duplicarlo dentro de uno.
- Borrar un país con comunidad, un tipo con capítulo o una categoría con gasto relacionado falla de forma controlada, conservando los registros. Las relaciones opcionales de universidad con capítulo y comunidad con proyecto se desvinculan según `nullOnDelete`, conservando capítulo y proyecto: no se clasificó ese comportamiento explícito del esquema como un fallo.
- Se rechazan catálogo desconocido, país inexistente, nombre demasiado largo y porcentajes negativos, exponenciales o con parte entera 101.
- Cambiar una categoría heredada de DR a WODR no reescribe el fondo ni el importe ya guardados en un gasto. Tampoco altera las definiciones IC/MAT del `BudgetCatalog` vigente.

Pruebas: `verification/catalogs-audit/CatalogosFlujoAuditTest.php`. `extraer-valores-ui.mjs` ejecuta las definiciones y funciones originales de valores iniciales de `Catalogs/Index.jsx`, sin modificarlo ni montar React; su resultado con hash de fuente se guarda en `valores-iniciales-ui.json`. Los demás resultados están en `resultados.xml`, `salida-pruebas.txt`, `pruebas-existentes.txt` y los JSON de cada caso, en el mismo directorio.

## ERR-081 — Cambiar el país de una comunidad deja proyectos vinculados con otro país

- **Estado:** confirmado por HTTP y lectura de relaciones sintéticas, pendiente. **Prioridad:** alta para la integridad de proyectos.
- **Ubicación:** `app/Http/Requests/SaveCatalogRequest.php:24`–`:28`; `app/Services/CatalogService.php:28`–`:29`. Contrato de coherencia existente: `app/Http/Requests/StoreProjectRequest.php:30`–`:34` y `app/Actions/Projects/SaveProject.php:25`–`:27`.

El alta y la actualización de proyectos exigen que la comunidad pertenezca al país seleccionado. Sin embargo, editar una comunidad solo comprueba que el país de destino exista y que el nombre no se duplique allí. El servicio cambia su país sin revisar los proyectos que ya la referencian.

**Reproducción:** crear país A, comunidad en A y proyecto con país A y esa comunidad. Como control, intentar crear directamente un proyecto con país B y la comunidad de A se rechaza con 422. Después, un administrador de catálogos cambia la comunidad al país B: el PATCH devuelve **302 sin errores**, pero el proyecto sigue con **país A**, vinculado a la comunidad que ahora declara **país B**.

La operación usa el permiso de catálogos; no se trata de editar directamente un proyecto sin autorización. El defecto es permitir que una mutación del dato de referencia rompa el invariante que protege el propio flujo de proyectos. Las claves foráneas independientes no comprueban la igualdad entre ambos países.

**Evidencia:** `comunidad-proyectos-paises-incoherentes.json` y `test_cambiar_pais_de_comunidad_conserva_la_coherencia_de_sus_proyectos`.

**Corrección propuesta:** validar y proteger la coherencia también al cambiar el país de una comunidad. La opción conservadora es rechazar el traslado mientras existan proyectos incompatibles, con un error explicativo y una comprobación transaccional consistente con el guardado de proyectos. Cualquier traslado coordinado debe autorizar explícitamente los proyectos afectados; no actualizarlos en bloque de forma implícita.

## ERR-082 — El cero inicial del formulario de categorías no cumple el tipo exigido por el servidor

- **Estado:** confirmado ejecutando los valores iniciales reales del frontend y enviándolos por HTTP, pendiente. **Prioridad:** media.
- **Ubicación:** `resources/js/Pages/Catalogs/Index.jsx:387`–`:398`; `app/Http/Requests/SaveCatalogRequest.php:37`.

`defaultValueFor()` devuelve el **número 0** para el campo `service_fee_percentage`, de tipo `number`. Si el usuario no lo cambia, ese es el valor del formulario. La solicitud, en cambio, exige que el porcentaje sea una **cadena** que cumpla el patrón decimal.

**Reproducción:** ejecutar `catalogDefinitions`, `buildDefaults` y `defaultValueFor` del archivo original mediante el extractor aislado. El porcentaje inicial resulta `0`, con tipo JavaScript `number`. Completar el nombre y enviar ese conjunto válido de valores iniciales mediante POST JSON, conservando las dos casillas desmarcadas. La respuesta es **422**, con error «El campo service fee percentage debe ser texto». Como control, enviar exactamente el cero como cadena **`"0"`** permite crear la categoría y devuelve 302.

La prueba no monta React ni simula clics: verifica la función original que construye los datos y el contrato HTTP que los recibe. No depende de un porcentaje negativo o de activar una comisión; el cero visible sin modificar ya provoca el rechazo.

**Evidencia:** `valores-iniciales-ui.json`, `porcentaje-inicial-ui-rechazado.json`, `extraer-valores-ui.mjs` y la prueba del porcentaje inicial.

**Corrección propuesta:** representar el porcentaje de forma coherente en el formulario y en la solicitud, por ejemplo inicializando el campo decimal con `"0"` y conservando su representación textual. Mantener la validación decimal estricta; no convertir mediante flotantes valores financieros arbitrarios para ocultar esta diferencia de tipos.

## ERR-083 — El límite de porcentaje permite superar 100 por la parte decimal

- **Estado:** confirmado por HTTP con controles de frontera, pendiente. **Prioridad:** media para la coherencia de validación.
- **Ubicación:** `app/Http/Requests/SaveCatalogRequest.php:37`.

El patrón limita la parte entera a 0–100, pero añade después una parte decimal opcional sin comparar el valor completo. Por eso rechaza 101,00 y acepta valores mayores que 100 cuya parte entera sigue siendo 100.

**Reproducción:** `100.00` se acepta; `101.00` se rechaza con 422; **`100.01` se acepta con 302 y queda guardado como 100,01**. Los demás campos son iguales y válidos. La diferencia no depende de un formato exponencial ni de números enviados como tipo incorrecto: todos son cadenas decimales.

La interpretación de 100 como techo procede del límite implementado en el propio patrón; no se atribuye a los Excel una política adicional sobre este catálogo heredado. Tampoco se afirma que esta categoría cambie la comisión de la matriz vigente, que usa reglas independientes.

**Evidencia:** `porcentaje-mayor-a-cien.json` y `test_porcentaje_superior_a_cien_se_rechaza_en_el_limite_decimal`.

**Corrección propuesta:** expresar el rango sobre el decimal completo, con comparación exacta y límites coherentes. Si el techo previsto es 100, rechazar cualquier fracción que lo exceda. Si se decide admitir porcentajes superiores, definir ese rango explícitamente y aplicarlo de forma uniforme, en lugar de permitir solo una fracción accidental sobre 100.

## ERR-084 — Se aceptan cuatro decimales de comisión, pero la lectura del catálogo solo devuelve dos

- **Estado:** confirmado en SQLite con lectura del valor bruto y del contrato Inertia, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Http/Requests/SaveCatalogRequest.php:37`; `app/Models/ExpenseCategory.php:23`; definición `DECIMAL(5,2)` en `database/migrations/2026_06_18_000100_create_fiea_core_tables.php:170`.

La solicitud admite hasta cuatro posiciones decimales, mientras que el cast de la categoría entrega dos y el esquema también declara escala dos. Se acepta una precisión que la aplicación no vuelve a presentar al consultar la categoría.

**Reproducción:** guardar **`5.1234`** como comisión. El alta se confirma sin errores. En SQLite, el valor bruto leído de la tabla es 5,1234, pero la respuesta real de `catalogs.index` entrega **`"5.12"`**. Al reenviar la fila publicada con un cambio únicamente en la descripción, la respuesta sigue siendo correcta para HTTP, pero la pantalla continúa sin representar la precisión aceptada inicialmente.

**Límite relevante:** en esta prueba SQLite el valor bruto siguió siendo 5,1234 después de ese guardado; **no se afirma que ese recorrido haya eliminado físicamente los decimales de la tabla SQLite**. Lo confirmado es la diferencia entre entrada aceptada, almacenamiento observado y lectura del modelo. No se ejecutó el caso en MySQL; su columna declarada tiene escala dos. Tampoco se atribuye un cambio a totales de facturas, que no se calculan desde este catálogo heredado.

**Evidencia:** `precision-porcentaje-ida-vuelta.json` y `test_precision_aceptada_del_porcentaje_se_conserva_al_reabrir_y_guardar`.

**Corrección propuesta:** definir una sola escala para entrada, modelo, esquema y formulario. Si se permiten dos decimales, rechazar una entrada con mayor precisión o normalizarla mediante una política explícita y visible. Si se requieren cuatro, adaptar de forma consistente almacenamiento y lectura, con un plan para los datos existentes. No aceptar silenciosamente una precisión diferente de la publicada.

## ERR-085 — La pantalla ofrece escritura a lectores y gestores sin alcance global aunque sus peticiones se rechazan

- **Estado:** confirmado por inspección estática de la interfaz y verificación HTTP de acceso, pendiente. **Prioridad:** baja, de experiencia de uso; la autorización del servidor se conserva.
- **Ubicación:** `resources/js/Pages/Catalogs/Index.jsx:92`, `:203`, `:236`, `:288`–`:289`; restricciones efectivas en `app/Http/Requests/SaveCatalogRequest.php:13` y `app/Services/CatalogService.php:44`.

La ruta de consulta admite visualizar o administrar catálogos. La página renderiza siempre el formulario «Nuevo registro», el botón de crear/guardar y los controles de editar y eliminar. Solo condiciona el botón principal al estado de procesamiento; no usa permisos ni alcance para determinar esas capacidades. Los componentes de botón inspeccionados tampoco añaden una comprobación propia.

**Verificación:** un usuario con **solo `catalogs.view`** abre el módulo con 200, pero alta, edición y eliminación responden **403**, sin modificar los registros. Un usuario con `catalogs.manage` y `scope_all = false` también puede consultar, pero no guardar. Ambos reciben la misma pantalla con las acciones de escritura que su cuenta no puede completar.

La presencia de los controles se verificó en el código que renderiza la página, no mediante una sesión de navegador ni una captura visual. El rechazo HTTP es correcto y constituye un control conforme de seguridad; la incidencia se limita a ofrecer un flujo que terminará en denegación.

**Evidencia:** `lectura-y-controles-ui.json`, las rutas verificadas en la prueba de permisos y los hashes de la página, `PrimaryButton` e `IconButton` en la navegación.

**Corrección propuesta:** derivar y entregar capacidades de escritura a partir del permiso y el alcance efectivos, y adaptar el formulario y acciones a esas capacidades. Un lector debe poder consultar sin completar un formulario que no puede guardar. Mantener siempre la revalidación del servidor y la del actor dentro de la transacción.

### Ampliación del problema de trazabilidad de ERR-080 a catálogos

El recorrido HTTP de alta, modificación y eliminación de una universidad se confirma, pero guarda **cero eventos**. `CatalogService` no integra ningún escritor de auditoría en `save()` ni en `destroy()`. La ausencia de evento de eliminación amplía el problema ya documentado en ERR-080; en este servicio también se observa para altas y cambios. Se deja evidencia en `propagacion-auditoria-a-catalogos.json` y una prueba deliberadamente fallida, **sin crear otro identificador ni aumentar el conteo**. La corrección debe incluir el contexto, actor y cambios permitidos en las mismas transacciones de catálogo.

### Límites del decimocuarto bloque

Las pruebas usan actores y registros sintéticos en **SQLite en memoria**. No se alteraron catálogos, proyectos, capítulos, gastos ni cuentas operativas. No se enviaron comunicaciones ni se tocaron los Excel originales. La comprobación de valores iniciales ejecuta funciones del frontend, pero no monta React ni prueba la validación nativa de un navegador. El hallazgo de controles ofrecidos sin capacidad se identifica expresamente como inspección estática más permisos HTTP. No se realizó una prueba de concurrencia real ni una certificación de redondeo en MySQL.

Se conservaron código de aplicación, dependencias y configuración persistente. La verificación final de hashes, libros originales, resultados e identificadores consecutivos está en `verification/catalogs-audit/cerrar_auditoria.py`, con resumen en `resumen.json`.

Este bloque añade **cinco incidencias nuevas: 85 incidencias confirmadas y pendientes en total**. El siguiente módulo propuesto es **estado y seguimiento de operaciones**, partiendo de `OperationStatusController` y `OperationHealthService` en el árbol de nodos.

## Auditoría del decimoquinto módulo: estado y seguimiento de operaciones

Fecha: **2026-10-29**, America/La_Paz. Se partió de `OperationStatusController` y `OperationHealthService`, siguiendo las conexiones hacia el comando de comprobación, almacenamiento, configuración, arranque, worker y planificador. El recorte del árbol de Graphify contiene **15 archivos indexados, 113 nodos y 159 enlaces**, con tiempos de modificación coincidentes con el manifiesto. Se conservaron además ocho archivos de contexto de configuración y documentación. Una búsqueda literal acotada a **12 puntos indexados de registro y ejecución** no encontró referencias a las dos claves de latido exigidas por el diagnóstico. Los archivos, coincidencias y hashes están en `verification/operations-audit/navegacion-graphify.json`.

Este módulo informa sobre infraestructura y preparación operativa; los Excel no definen sus latidos, sondas ni códigos de salida. Se contrastó lo que anuncia el diagnóstico con el comportamiento real de sus dependencias aisladas y con los comandos documentados, sin derivar instrucciones de los documentos ni afirmar equivalencia completa con los libros.

**Resultado:** seis incidencias nuevas, ERR-086 a ERR-091. Las pruebas aisladas suman **16 casos y 63 aserciones: cinco casos conformes y once fallos deliberados de contrato**, sin errores de ejecución de PHPUnit. Varias pruebas se agrupan por el mismo defecto: dos para caché, tres para latidos, dos para almacenamiento y dos para las tablas de cola. La suite existente relacionada pasa: **8 casos, 39 aserciones**.

### Controles conformes y evidencia

- El invitado se redirige al login; el usuario sin `operations.view` recibe 403. La carga diferida autorizada entrega los trece chequeos y la fecha de comprobación.
- Con todas las dependencias y atestaciones sintéticas configuradas, el estado es `ok`. `fiea:production-check --json` solicita un diagnóstico fresco y no reutiliza un informe obsoleto de la caché operativa.
- Un esquema financiero incompleto produce `failed`, conservando los demás chequeos.
- Una excepción aislada de escritura de almacenamiento se representa como fallo con una referencia UUID y sin copiar su mensaje reservado al informe; se siguen entregando los trece chequeos.
- Una advertencia ordinaria produce salida 0 por defecto y salida 1 con `--fail-on-warning`. El defecto de ERR-091 es tratar una dependencia criptográfica obligatoria como esa clase de advertencia.

Pruebas y resultados: `verification/operations-audit/OperacionesFlujoAuditTest.php`, `resultados.xml`, `salida-pruebas.txt`, `pruebas-existentes.txt` y los JSON particulares. Las pruebas nuevas usan almacenamiento simulado y caché en memoria. Para la suite existente se fijó `FILESYSTEM_DISK=local` únicamente en su proceso: sus sondas locales temporales no utilizaron R2 ni otros servicios externos.

## ERR-086 — Un fallo de caché puede impedir el diagnóstico que debería comunicarlo

- **Estado:** confirmado por HTTP y por invocación del comando, con fallos de lectura específicos, pendiente. **Prioridad:** alta para disponer de diagnóstico durante incidencias.
- **Ubicación:** `app/Services/OperationHealthService.php:29`–`:30`, `:285` y `:308`.

La lectura del informe mediante `Cache::remember` queda fuera de una captura de errores. Aunque el chequeo individual de caché sí intenta representar errores como un estado `failed`, no puede hacerlo cuando la lectura envolvente impide llegar a él. Las lecturas de latidos tampoco están protegidas, por lo que pueden interrumpir una comprobación fresca.

**Dos reproducciones específicas:**

1. Configurar una caché de prueba que falla únicamente al leer `fiea:operations:health`. Una petición autorizada de las props diferidas recibe **500**, sin `props.health`, en vez del diagnóstico estructurado. La evidencia registra que se alcanzó exactamente esa lectura.
2. Mantener disponibles las demás claves y fallar solo al leer `fiea:queue:heartbeat`. El comando `fiea:production-check --json`, que pide datos frescos, deja salir una **RuntimeException** y no devuelve su informe ni su código normal. También se registra la clave exacta que falló.

No se simula aquí que toda la autenticación o toda la infraestructura hayan dejado de funcionar: los fallos son deliberadamente acotados a lecturas del diagnóstico. La falta de aislamiento de esas lecturas es la causa reproducida.

**Evidencia:** `cache-informe-rompe-http.json`, `cache-latido-aborta-comando.json` y sus dos pruebas.

**Corrección propuesta:** disponer de una vía de diagnóstico sin caché cuando falle la caché del informe, y aislar las lecturas de cada dependencia y sus latidos. Conservar los chequeos que puedan ejecutarse, representar el componente averiado con una referencia de error y devolver un estado global/código de salida coherente. No ocultar el fallo convirtiéndolo en `ok`.

## ERR-087 — Los latidos exigidos no se publican en el flujo revisado y su comprobación no valida antigüedad

- **Estado:** confirmado con una ejecución real y aislada de un trabajo y una tarea sintéticos, más inspección de registro y prueba de antigüedad, pendiente. **Prioridad:** alta para la fiabilidad de la monitorización.
- **Ubicación:** `app/Services/OperationHealthService.php:285` y `:308`; puntos de registro de `app/Providers/AppServiceProvider.php`, `bootstrap/providers.php` y `routes/console.php`.

En producción se exige la existencia de `fiea:queue:heartbeat` y `fiea:scheduler:heartbeat`. No se encontró un productor de esas claves en los proveedores, arranque, comandos y trabajos indexados inspeccionados. La documentación revisada indica cómo ejecutar el worker y el planificador, pero no establece esa publicación. Además, el consumidor solo usa `Cache::has`: no comprueba el valor ni la fecha que contiene la clave.

**Tres reproducciones:**

- En una base SQLite en memoria, ejecutar una sola iteración de `queue:work` contra la cola exclusiva `auditoria-operaciones`, con un trabajo que únicamente escribe un marcador de prueba. El trabajo se procesa, queda confirmado su marcador y la tabla de pendientes queda vacía. Sin embargo, no aparece el latido exigido y el chequeo de cola informa **`warning`** por ausencia de latido.
- Sustituir la instancia de `Schedule` por una que contiene **solo una tarea sintética** y ejecutar `schedule:run`. La tarea escribe su marcador y termina correctamente, pero tampoco aparece el latido del planificador y su chequeo queda en **`warning`**. No se ejecutan los comandos de correo, respaldo ni outbox del proyecto.
- Introducir en caché marcadores con fecha de **hace un año**, sin caducidad. Ambos chequeos pasan a **`ok`**: basta la existencia de las claves, aunque sus valores no acrediten actividad reciente.

La prueba de antigüedad no afirma que una clave con TTL correcto sobreviva a su vencimiento. Demuestra que el consumidor depende de una garantía de caducidad/publicación que la integración revisada no establece. No se verificó un productor externo de latidos: el hallazgo corresponde al código y al procedimiento de ejecución inspeccionados.

**Evidencia:** `worker-activo-sin-latido.json`, `scheduler-activo-sin-latido.json`, `latidos-antiguos-aceptados.json` y la sección `registro_latidos` de la navegación.

**Corrección propuesta:** definir e instrumentar un contrato único de latido en los ciclos efectivos del worker y del planificador, incluyendo ejecución sin trabajos, identidad relevante y caducidad. Verificar un valor temporal válido y su antigüedad máxima, además de la existencia de la clave. Documentar el productor, frecuencia y almacén compartido; no resolver la advertencia creando marcadores permanentes manuales.

## ERR-088 — La sonda de almacenamiento anuncia lectura sin leer y omite el fallo de limpieza

- **Estado:** confirmado con adaptadores de almacenamiento simulados, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Services/OperationHealthService.php:218`–`:232`.

La sonda escribe `ok`, comprueba `exists()` y solicita borrar el archivo. No lee su contenido ni comprueba el resultado del borrado. Aun así, el detalle anuncia que el disco permite «escritura y lectura».

**Dos reproducciones:**

1. Un adaptador de prueba permite escritura, consulta de existencia y borrado, pero lanza una excepción en la lectura de contenido. Una llamada de control comprueba esa denegación. La sonda nunca intenta leer y devuelve **`ok`**, anunciando lectura disponible.
2. Otro adaptador escribe y confirma la existencia, pero devuelve **false al borrar**. El chequeo también informa **`ok`** y queda **una sonda** en el almacén simulado. No se informa del fallo de limpieza.

El primer caso verifica el contrato abstracto del adaptador, no una política específica de permisos de R2. El segundo se refiere a un fallo de borrado explícito: no se afirma que la limpieza normal falle. Las sondas y las denegaciones utilizadas son exclusivamente de prueba.

**Evidencia:** `sonda-no-lee-contenido.json`, `sonda-sin-limpiar.json` y sus dos pruebas.

**Corrección propuesta:** leer y cotejar el contenido de la sonda y comprobar los resultados de las operaciones que se anuncian como verificadas. Garantizar un intento de limpieza en una sección final, comunicar si falla y no declarar plenamente correcto ese chequeo cuando conserva artefactos sin limpiar. Mantener el carácter privado del objeto y referencias de error sin secretos.

## ERR-089 — Los chequeos de cola ignoran las tablas configuradas y pueden ocultar una cola averiada o fallos existentes

- **Estado:** confirmado contra un backend database y tablas sintéticas en SQLite, pendiente. **Prioridad:** alta para detectar interrupciones y trabajos fallidos.
- **Ubicación:** `app/Services/OperationHealthService.php:270`, `:294` y `:324`–`:325`; configuración admitida en `config/queue.php`.

El diagnóstico comprueba literalmente `jobs` y cuenta literalmente `failed_jobs` en la base por defecto. No utiliza la tabla/conexión configurada para la cola ni la configurada para su proveedor de trabajos fallidos.

**Dos reproducciones:**

- Mantener la tabla predeterminada `jobs`, pero configurar el backend `database` para usar `cola_auditoria_inexistente`. Una consulta real de tamaño al backend de cola **falla**, porque su tabla configurada no existe. Con un marcador de latido sintético presente, el diagnóstico informa **`Colas: ok`** y `jobs_table: true`, comprobando la tabla equivocada.
- Crear `fallos_auditoria`, configurarla como `queue.failed.table` y guardar allí **un trabajo fallido sintético**. Dejar vacía `failed_jobs`. El diagnóstico devuelve **`Trabajos fallidos: ok`, count = 0**, aunque la tabla configurada contiene el fallo.

Los datos se limitan a una base de memoria. No se consultó ni se cambió otra conexión de producción. Las dos reproducciones demuestran el uso de tablas fijas; no se certificó el funcionamiento de Redis, SQS u otros proveedores.

**Evidencia:** `cola-tabla-configurada-ignorada.json`, `fallos-tabla-configurada-ignorada.json` y sus dos pruebas.

**Corrección propuesta:** resolver el backend, conexión y tabla desde la configuración efectiva y consultar su capacidad real mediante un diagnóstico adecuado al proveedor. Para trabajos fallidos, utilizar el proveedor configurado o un adaptador equivalente, respetando dónde almacena sus registros. Aislar errores y usar límites de tiempo para comprobaciones que requieran conexión; no inferir salud de una tabla predeterminada ajena al backend efectivo.

## ERR-090 — Un respaldo reciente sin archivo conserva estado operativo correcto

- **Estado:** confirmado con un registro y objeto sintéticos, pendiente. **Prioridad:** alta para no confundir registro histórico con disponibilidad del respaldo.
- **Ubicación:** `app/Services/OperationHealthService.php:349`–`:361`.

El chequeo de respaldo toma la fecha máxima de ejecuciones `completed` y una marca temporal de ensayo de restauración en caché. No comprueba si existe el `StorageFile` correspondiente ni si su objeto sigue disponible.

**Reproducción:** preparar una ejecución sintética completada hace una hora, con su metadato de archivo y un objeto disponible en el disco simulado. Preparar también la marca reciente de ensayo como dato de la fixture. Confirmar que el objeto existe y borrarlo únicamente de ese disco de prueba, manteniendo el registro. El chequeo sigue devolviendo **`ok`**, con el detalle «Respaldo reciente y ensayo de restauración registrados», aunque el archivo ya no existe.

La fixture no contiene un volcado operativo ni acredita una restauración real. La prueba aísla el fallo de disponibilidad posterior al registro; no certifica el cifrado ni plantea que deba ejecutarse una restauración completa en cada consulta del panel. Tampoco duplica la incompatibilidad del escritor SQLite ya registrada en ERR-071.

**Evidencia:** `respaldo-reciente-sin-archivo.json` y `test_respaldo_con_archivo_ausente_no_figura_como_recuperable`.

**Corrección propuesta:** distinguir último respaldo registrado de último respaldo disponible y verificado. Comprobar de forma acotada su referencia y objeto, y conservar una evidencia verificable de integridad/ensayo con su identidad y fecha. Un objeto ausente debe producir atención o fallo, aunque su ejecución histórica siga marcada como completada. No introducir restauraciones destructivas desde el chequeo de salud.

## ERR-091 — La ausencia de APP_KEY no bloquea el comando predeterminado aunque el cifrado no pueda funcionar

- **Estado:** confirmado con configuración temporal de prueba y resolución real del cifrador, pendiente. **Prioridad:** alta para la comprobación de despliegue.
- **Ubicación:** `app/Services/OperationHealthService.php:373`–`:378` y `:416`; `app/Console/Commands/CheckProductionReadiness.php:28`–`:36`.

La lista de configuración identifica `APP_KEY` como variable crítica, pero su ausencia solo produce `warning`. El comando predeterminado devuelve éxito para advertencias, salvo que se añada `--fail-on-warning`. Por eso puede aprobar una configuración que no dispone del cifrado requerido por la aplicación.

**Reproducción:** partir de una fixture donde los trece chequeos están en `ok`. Retirar `app.key` solo en memoria y resolver de nuevo el cifrador del framework. El intento de cifrado de una cadena sintética **falla**, confirmando que esa dependencia no funciona. Aun así, `fiea:production-check --json` devuelve **código 0**, estado general **`warning`**, y lista `APP_KEY` entre las variables críticas ausentes.

El control de una advertencia ordinaria, como la falta de marca de ensayo de restauración, mantiene correctamente la diferencia entre ejecución normal y `--fail-on-warning`. La incidencia no pide convertir toda advertencia en fallo: afecta a una condición que impide utilizar una dependencia obligatoria. No se retiró ni reveló ninguna clave operativa.

**Evidencia:** `clave-app-ausente-no-bloquea.json` y `test_falta_app_key_bloquea_comprobacion_predeterminada`.

**Corrección propuesta:** clasificar como `failed` la ausencia o invalidez de dependencias criptográficas obligatorias, independientemente del modo estricto de advertencias. Mantener las recomendaciones no bloqueantes como `warning` y verificar que el comando normal rechaza una configuración incapaz de cifrar. Nunca imprimir claves ni intentar regenerarlas automáticamente desde este diagnóstico.

### Límites del decimoquinto bloque

Los actores, respaldos, tablas y trabajos usados son sintéticos. La base es **SQLite en memoria**; la caché y almacenamiento de las pruebas nuevas están simulados. La etiqueta de entorno `production` se activa solo dentro de la aplicación de pruebas para ejercitar esas ramas. El worker procesa únicamente un trabajo inocuo en una cola exclusiva, en una sola iteración; el planificador usa una instancia sustituida que contiene solo el callback de prueba. **No se ejecutaron los comandos operativos de correo, respaldo ni outbox, ni un worker en segundo plano.**

No se enviaron comunicaciones, extrajeron bases ni hicieron restauraciones. Las marcas de ensayo de respaldo son entradas sintéticas para probar el diagnóstico, no certificaciones de recuperación. No se certificaron Redis, R2, MySQL ni un productor externo de latidos; las excepciones de lectura/limpieza y caché están identificadas como inyecciones controladas. No hubo revisión visual completa del navegador.

Se conservaron el código de aplicación, dependencias, configuración persistente y ambos Excel originales. Los hashes de las fuentes inspeccionadas, referencias, resultados e identificadores consecutivos se comprueban en `verification/operations-audit/cerrar_auditoria.py`, con salida en `resumen.json`.

Este bloque añade **seis incidencias nuevas: 91 incidencias confirmadas y pendientes en total**. El siguiente módulo propuesto es **perfil personal**, siguiendo `ProfileController` y sus vecinos en el árbol de nodos.

## Auditoría del decimosexto módulo: perfil personal y credenciales

Fecha: **2026-10-29**, America/La_Paz. Se partió de `ProfileController`, `ProfileUpdateRequest` y `PasswordController`, siguiendo sus conexiones hacia cuenta utilizable, autenticación, administración y referencias financieras. El recorte de Graphify comprende **31 archivos indexados, 232 nodos y 288 enlaces**, con tiempos de modificación coincidentes con el manifiesto. Se conservaron además seis archivos de contexto de arranque, pruebas y adaptadores instalados. Navegación y hashes: `verification/profile-audit/navegacion-graphify.json`.

Los Excel no establecen el contrato de identidad, cookies, eliminación o cambio de contraseña. Se contrastó el perfil con los demás flujos que operan sobre las mismas cuentas y con sus restricciones reales. No se interpretaron documentos como instrucciones del usuario ni se declaró equivalencia completa con los libros.

**Resultado:** cuatro incidencias nuevas, ERR-092 a ERR-095, más ampliaciones de ERR-065, ERR-068, ERR-069 y ERR-080 sin volver a contarlas. Las pruebas aisladas suman **17 casos y 156 aserciones: ocho casos conformes y nueve fallos deliberados de contrato**, con cero errores de ejecución de PHPUnit. ERR-095 tiene dos reproducciones; ERR-094 combina una respuesta HTTP comprobada con inspección del comportamiento del cliente, sin montar React. La suite existente relacionada pasa: **14 casos, 94 aserciones**.

### Controles conformes y evidencia

- La edición solo actualiza nombre, usuario y correo de la cuenta autenticada. Los intentos de introducir ID ajeno, roles, alcance global, actividad, contraseña o estado de cambio obligatorio no modifican esos campos protegidos ni la otra cuenta.
- Cambiar el correo elimina su verificación; mantenerlo conserva la verificación existente. Se rechazan duplicados, usuario con espacios y correo inválido.
- Invitado y cuenta inactiva no editan. El cambio de contraseña exige la actual y una confirmación coincidente; un cambio válido elimina el estado temporal obligatorio.
- Un usuario ordinario sin dependencias solo puede autoborrarse con su contraseña correcta y no puede escoger otro usuario en el cuerpo. La eliminación válida cierra su sesión.
- La actividad diferida contiene únicamente eventos propios, no entrega metadata reservada y limita el user agent mostrado a 500 caracteres. En el SQLite probado, quince eventos con la misma fecha devolvieron los doce IDs más nuevos: no se reprodujo aquí el defecto de empates de otro módulo ni se añadió una incidencia por él. Esta observación no certifica un orden idéntico en cualquier motor o plan de consulta.
- El control de cookies demuestra que un contexto sin ellas es invitado. Una rotación explícita del token realizada **solo en la fixture de control** hace que la cookie de recuerdo antigua se rechace, identificando la causa de ERR-093 sin cambiar el controlador real.

Pruebas y resultados: `verification/profile-audit/PerfilPersonalFlujoAuditTest.php`, `resultados.xml`, `salida-pruebas.txt`, `pruebas-existentes.txt` y los JSON por escenario. Las cookies se obtienen y reutilizan mediante peticiones HTTP de prueba, con sesiones `file` en directorios exclusivos eliminados al finalizar. Los JSON no guardan cookies, tokens, contraseñas ni hashes de credenciales.

## ERR-092 — El autoborrado con referencias financieras termina en error 500 después de cerrar la sesión

- **Estado:** confirmado por HTTP con una referencia financiera protegida, pendiente. **Prioridad:** media para el flujo de eliminación y sus restricciones.
- **Ubicación:** `app/Http/Controllers/ProfileController.php:66`–`:80`; restricción de `accounting_reviews.reviewed_by_id` en `database/migrations/2026_10_25_000200_add_financial_workbooks.php:182`.

El controlador valida la contraseña, cierra la autenticación y después intenta borrar el usuario sin comprobar ni manejar las restricciones de sus referencias financieras. Varias tablas nuevas preservan esos responsables mediante claves foráneas con `restrictOnDelete`.

**Reproducción:** preparar un usuario sintético y una revisión contable que lo identifica como revisor, vinculada a un caso real de la fixture. Enviar DELETE del perfil con la contraseña correcta. La clave foránea impide borrar al responsable y conserva tanto usuario como revisión, pero la petición termina en **500**, con la sesión ya cerrada. No se presenta una explicación controlada de por qué la cuenta no puede eliminarse.

La retención referencial funciona: no se propone borrado en cascada ni eliminar la evidencia financiera. El defecto es el manejo de una restricción válida y el orden de cierre de sesión. El control de usuario ordinario sin dependencias sí completa el autoborrado.

**Evidencia:** `borrado-referencia-financiera-500.json` y `test_borrado_con_referencia_financiera_se_rechaza_sin_error_500`.

**Corrección propuesta:** centralizar la eliminación y sus invariantes, comprobar las referencias pertinentes y manejar un rechazo referencial como error de dominio explicativo. Conservar la sesión cuando la eliminación no se confirma y cerrarla tras el resultado válido. No debilitar restricciones financieras ni la protección del último administrador para que el botón funcione.

## ERR-093 — Cambiar la contraseña conserva una cookie antigua de recordarme capaz de autenticar una sesión nueva

- **Estado:** confirmado con login, cambio de contraseña y reutilización HTTP de cookie, pendiente. **Prioridad:** alta.
- **Ubicación:** `app/Http/Controllers/Auth/PasswordController.php:23`–`:27`; comportamiento instalado de recuperación por token en `vendor/laravel/framework/src/Illuminate/Auth/SessionGuard.php`, método `userFromRecaller`.

El cambio propio modifica el hash de contraseña y regenera el identificador de sesión, pero no rota `remember_token`. El guard instalado recupera la cuenta con el identificador y ese token de la cookie de recuerdo. Por eso una cookie obtenida antes del cambio sigue siendo una credencial válida para crear una sesión nueva.

**Reproducción HTTP:**

1. Iniciar sesión con «recordarme» y conservar su cookie únicamente dentro de la prueba.
2. Cambiar la contraseña mediante `password.update`, presentando la actual y una confirmación válida. Se comprueba que el hash nuevo acepta la nueva contraseña y que el token de recuerdo permanece igual.
3. Reiniciar el contexto web, retirar todas las cookies y comprobar que el panel redirige al login.
4. Reiniciar de nuevo y presentar **solo la cookie antigua de recordarme**, sin la cookie de la sesión anterior. El panel responde **200**, autenticando al usuario mediante **`viaRemember = true`**, sin presentar la contraseña nueva.

Como control, repetir el escenario rotando el token únicamente en la fixture hace que esa misma clase de cookie anterior se rechace: devuelve 302 al login y no autentica. No se aplicó esa rotación como corrección de producción.

Este caso es distinto de ERR-068: allí sobrevive una sesión de archivos ya creada, incluso en un flujo de recuperación que sí rota el token de recuerdo. Aquí se inicia una autenticación persistente nueva mediante un token que el cambio propio no revoca. El escenario usa archivos para aislar las sesiones, pero la evidencia distingue explícitamente autenticación por recuerdo de reutilización de una sesión activa.

**Evidencia:** `recordarme-tras-cambio-clave.json`, `control-rotacion-token-fixture.json` y sus dos pruebas.

**Corrección propuesta:** integrar el cambio propio en una política coherente de revocación de credenciales persistentes, rotando el token de recuerdo con los mecanismos del framework. Definir qué sesión actual se conserva y cómo se renueva una cookie legítima, y coordinarlo con la invalidación de otras sesiones de ERR-068. No depender solo de regenerar el identificador de la petición que cambió la contraseña.

## ERR-094 — Una edición bloqueada por cambio obligatorio de clave devuelve éxito de navegación sin guardar los datos

- **Estado:** confirmado por HTTP Inertia y por inspección del cliente instalado, pendiente. **Prioridad:** media para informar correctamente el resultado.
- **Ubicación:** `app/Http/Middleware/EnsureAccountUsable.php:22`–`:26`; `app/Http/Middleware/HandleInertiaRequests.php`, datos compartidos del usuario; `resources/js/Pages/Profile/Partials/UpdateProfileInformationForm.jsx:19`, `:126`–`:133`.

Una cuenta con `must_change_password = true` puede abrir el perfil para cambiar su clave, pero no puede actualizar sus datos personales. La pantalla sigue ofreciendo el formulario normal. Al intentar guardarlo, el middleware devuelve una redirección con `flash.success` en vez de comunicar al formulario que la operación se rechazó.

**Reproducción:** abrir el perfil de una cuenta con cambio obligatorio y enviar un nombre distinto mediante PATCH Inertia, con el resto de datos válidos. La respuesta es **303** hacia el perfil. Los datos **no cambian**, pero la página de destino contiene **`errors = []`** y `flash.success = "Debes cambiar tu contraseña temporal para continuar."`. El usuario compartido tampoco incluye el estado `must_change_password` para que este formulario lo distinga.

El adaptador `@inertiajs/react` instalado activa `recentlySuccessful` en su `onSuccess`, y el formulario usa ese indicador para mostrar «Guardado». Se verificaron la respuesta y el código que determina esa señal; no se montó React ni se afirma haber observado una captura del mensaje en un navegador. El resultado de la mutación sigue siendo incorrectamente compatible con éxito para el cliente, aunque la explicación del flash mencione el requisito de contraseña.

La obligación de cambiar la clave sí se aplica correctamente y debe conservarse. El fallo no es que permita modificar los datos, sino ofrecer el formulario y devolver una respuesta que no distingue el rechazo de un guardado correcto.

**Evidencia:** `edicion-bloqueada-sin-error-inertia.json`, la prueba correspondiente y los hashes del formulario y adaptador instalado en la navegación.

**Corrección propuesta:** exponer el estado o las capacidades pertinentes para presentar el perfil adecuadamente durante un cambio obligatorio. Si se intenta una mutación bloqueada, responder con una denegación que el formulario pueda representar como tal y evitar señales de guardado. Mantener las redirecciones de navegación que correspondan y el acceso al cambio de contraseña permitido.

## ERR-095 — Perfil y administración admiten identificadores que el otro flujo rechaza sin modificarlos

- **Estado:** confirmado mediante dos recorridos HTTP con login válido, pendiente. **Prioridad:** media para administrar y editar cuentas existentes.
- **Ubicación:** `app/Http/Requests/ProfileUpdateRequest.php:25`–`:26`; `app/Http/Requests/StoreUserRequest.php:18`; `app/Http/Requests/UpdateUserRequest.php:13`.

Perfil admite hasta **120 caracteres**, pero exige `alpha_dash:ascii`. Alta y edición administrativa admiten hasta **80 caracteres**, con `alpha_dash` sin esa restricción ASCII. Un valor válido para una ruta puede quedar inválido al abrir y guardar otra ruta que opera sobre el mismo campo.

**Dos reproducciones:**

1. Cambiar desde perfil el usuario por una cadena ASCII en minúsculas de **81 caracteres**. El cambio se guarda y el login posterior con ese identificador funciona. Después, un administrador intenta desactivar esa cuenta conservando su nombre de usuario: la edición devuelve **422 por `username`**, con el mensaje de máximo 80 caracteres. La cuenta sigue activa. El administrador tendría que modificar también un identificador ya aceptado para completar esa operación.
2. Crear mediante el alta administrativa `usuario_ñ`, completamente en minúsculas. El alta y el login funcionan. Intentar cambiar solo el nombre personal desde su perfil, conservando ese mismo identificador, devuelve **422 por `username`** y no guarda el nombre: perfil exige ASCII.

No se repite aquí el problema de mayúsculas y login de ERR-067: en ambos casos nuevos el login sí funciona. El defecto es el contrato incompatible entre formularios posteriores y la falta de una política común para los datos existentes.

**Evidencia:** `usuario-largo-perfil-no-administrable.json`, `usuario-unicode-alta-no-editable-en-perfil.json` y sus dos pruebas.

**Corrección propuesta:** centralizar longitud, caracteres admitidos, representación y unicidad del identificador para alta, administración, perfil y login. Elegir una política explícita de ASCII/Unicode y aplicar una transición compatible a cuentas existentes. Evitar renombrados implícitos o que una operación administrativa ajena al identificador obligue a alterarlo para superar la validación.

### Ampliaciones de incidencias previas, sin aumentar su conteo

- **ERR-065, protección del último administrador:** un único administrador activo, global y con permiso de administración puede autoborrarse desde perfil con su contraseña correcta. El DELETE devuelve 302 y quedan **cero administradores operativos**. Esta vía omite el mismo invariante ya registrado. Evidencia: `propagacion-ERR-065-autoborrado-admin.json`.
- **ERR-068, sesiones anteriores:** después del cambio propio, la cookie de sesión `file` anterior también reabre el panel con 200. Su archivo sigue existiendo con el mismo contenido y `viaRemember = false`; el contexto sin cookies se comprobó primero como invitado. Es la ampliación de revocación de sesiones ya documentada, diferenciada del token persistente de ERR-093. Evidencia: `propagacion-ERR-068-cambio-propio.json`.
- **ERR-069, límite bcrypt:** el cambio propio admite una clave de 84 bytes. Otra distinta, también de 84 bytes y con los primeros 72 idénticos, se acepta en el cotejo; cambiar un byte del prefijo sí se rechaza. No se afirma que funcione cualquier contraseña incorrecta. Evidencia: `propagacion-ERR-069-cambio-propio.json`.
- **ERR-080 y su ampliación de trazabilidad:** editar el perfil, cambiar la clave y eliminar una cuenta sin dependencias se confirman sin guardar ningún evento. El escenario registra **cero eventos para las tres operaciones**. La corrección de trazabilidad debe cubrir también estas rutas, sin almacenar contraseñas ni tokens. Evidencia: `propagacion-ERR-080-perfil-sin-traza.json`.

### Límites del decimosexto bloque

Se usaron cuentas, roles, proyectos y referencias financieras sintéticos en **SQLite en memoria**. Los directorios UUID de sesiones de archivos pertenecen exclusivamente a esta auditoría y quedaron vacíos de fixtures al finalizar. La rotación del token utilizada como control solo cambia una cuenta sintética; no modifica el controlador ni una cuenta operativa.

No se enviaron correos ni se modificaron cuentas o sesiones operativas; tampoco se alteraron los Excel originales. No se hicieron pruebas de concurrencia real MySQL ni una revisión visual completa del navegador. Los detalles de comportamiento de cookies y formularios se contrastaron con las versiones instaladas, cuyos hashes se registraron.

Se conservaron código de aplicación, dependencias y configuración persistente. `verification/profile-audit/cerrar_auditoria.py` verifica fuentes, libros originales, limpieza de sesiones, resultados e identificadores consecutivos, y guarda `resumen.json`.

Este bloque añade **cuatro incidencias nuevas: 95 incidencias confirmadas y pendientes en total**. El siguiente módulo propuesto es **preferencias de interfaz y temas**, partiendo de `UserThemePreferenceController`, `LiquidGlassController` y sus vecinos en el árbol de nodos.

## Auditoría del decimoséptimo módulo: preferencias de interfaz y temas

Fecha: **2026-10-29**, America/La_Paz. Se partió de `UserThemePreferenceController`, `LiquidGlassController` y `LiquidGlassSettings`, siguiendo el árbol hacia `ThemeToggle`, utilidades de tema, escenas y motor óptico. El recorte de Graphify comprende **30 archivos indexados, 188 nodos y 320 enlaces**, con tiempos de modificación coincidentes con el manifiesto. Se registraron además siete archivos de contexto, incluida la plantilla de arranque, CSS y versiones instaladas del motor/adaptador. Navegación y hashes: `verification/appearance-audit/navegacion-graphify.json`.

Los Excel no especifican estos ajustes cosméticos. Se compararon validación, persistencia, datos publicados y consumidores reales, manteniendo separadas las reglas financieras. No se trataron textos de documentos como instrucciones del usuario ni se declaró equivalencia completa del sistema con los libros.

**Resultado:** dos incidencias nuevas, ERR-096 y ERR-097, y ampliaciones de ERR-066, ERR-086 y ERR-094 sin volver a contarlas. La auditoría aislada comprende **19 casos**: nueve de backend con **59 aserciones**, y diez de frontend/motor/navegador. Hay **once casos conformes y ocho fallos deliberados de contrato**, con cero errores de ejecución en ambas herramientas. Un mismo defecto se comprueba en varias capas: ERR-096 en la API y el cálculo del motor, ERR-097 en dos condiciones de arranque y ERR-094 en servidor y control React.

La suite existente relacionada pasa: **5 casos PHP, 66 aserciones**, y **4 casos de frontend**. Se empleó **Chrome 155.0.8059.85 sin interfaz**, con contextos temporales y navegación exclusiva a un dominio sintético cuyas respuestas se interceptaron; las demás solicitudes de las páginas se abortaron.

### Controles conformes y evidencia

- El tema personal admite `light`, `dark` y `system`, rechaza otros valores y solo cambia la cuenta autenticada. Un ID ajeno y propiedades adicionales no alteran otra cuenta ni su actividad. Invitado e inactivo no guardan cambios.
- El estudio requiere el permiso administrativo y el rol exigido; tener solo uno no permite publicar. Se rechazan parámetros fuera de rango y propiedades desconocidas.
- Publicar valores tipados invalida la caché, conserva actor y auditoría y entrega los perfiles nuevos a otra cuenta y al login. Se ejercitó la rama con caché habilitada, incluyendo un token CSRF válido para esa petición.
- Un fallo al escribir auditoría revierte la publicación. La comparación considera la representación JSON de los perfiles, sin confundir ceros enteros y flotantes equivalentes tras su almacenamiento.
- En Chrome, el componente `ThemeToggle` original actualiza DOM, almacenamiento local y petición al recibir el 204 simulado. Si la petición se rechaza, revierte el control y la preferencia local. Se simulan el contexto Inertia y la respuesta de Axios, no el componente ni React.
- Las utilidades originales siguen los cambios de modo del sistema y retiran ese seguimiento al elegir un tema explícito. Un `localStorage` bloqueado produce los fallbacks previstos sin excepción.
- El control del motor con valores numéricos y booleanos tipados conserva un cálculo válido. El control de CSP con el nonce añadido **solo a la fixture HTML** permite ejecutar el mismo inicializador, sin cambiar la aplicación ni relajar su política.

Pruebas: `verification/appearance-audit/PreferenciasInterfazFlujoAuditTest.php` e `InterfazNavegadorAudit.mjs`. Resultados: `resultados.xml`, `resultados-frontend.json`, sus salidas de texto y las salidas de las suites existentes, en el mismo directorio. Los perfiles y el inicializador/CSP utilizados por el frontend proceden de respuestas reales de la aplicación de pruebas, guardadas en `perfiles-cadenas.json` e `inicializador-csp.json`.

## ERR-096 — Los perfiles ópticos conservan cadenas válidas para el servidor que cambian su significado en el cliente

- **Estado:** confirmado con publicación HTTP y ejecución del cálculo original del motor instalado, pendiente. **Prioridad:** media.
- **Ubicación:** `app/Http/Requests/UpdateLiquidGlassRequest.php:22`–`:26`; `app/Http/Controllers/LiquidGlassController.php:32` y `:36`; propagación en `resources/js/Components/Glass/glassFactory.js:7`; consumo en `node_modules/@ybouane/liquidglass/dist/index.js:2323`–`:2330`.

Las reglas `numeric`, `integer` y `boolean` admiten determinadas cadenas, pero la validación no las transforma. El controlador guarda y publica directamente el array validado. El consumidor mezcla ese array sin normalizar los tipos, y el motor realiza operaciones y decisiones propias de números y booleanos JavaScript.

**Recorrido reproducido:** publicar un perfil de botón con `brightness = "0.1"`, `button = "0"`, `floating = "0"` y `bevelMode = "1"`, conservando válidos los demás campos. La API devuelve **302 sin errores**. La lectura publicada mantiene esos valores como **cadenas**.

Pasar ese perfil real a `glassFactory` y al método `_getConfig` de la biblioteca instalada, con estado hover de prueba, produce dos diferencias relevantes: `"0"` es verdadero para la condición JavaScript del botón, y sumar 0,2 a `"0.1"` concatena **`"0.10.2"`**, que no representa un brillo numérico válido. El control con números y booleanos auténticos produce aproximadamente **0,3** y conserva un valor numérico finito.

La prueba ejecuta el cálculo original del motor sin recrearlo; no certifica el resultado visual del shader ni afirma haber observado una GPU renderizando ese valor. El formulario normal convierte sus sliders a números, pero el contrato HTTP acepta y publica las otras representaciones, que sus consumidores no interpretan de forma equivalente.

**Evidencia:** `perfiles-cadenas.json`, la prueba PHP de normalización y los dos primeros casos de `resultados-frontend.json`.

**Corrección propuesta:** normalizar los campos permitidos después de validarlos y antes de persistir, auditar o publicar: números finitos para parámetros continuos, enteros para selectores y booleanos reales para interruptores. Aplicar un contrato equivalente al cargar perfiles existentes. No usar la veracidad genérica de una cadena en JavaScript como sustituto de interpretar un booleano validado.

## ERR-097 — El inicializador anterior a React no aplica el tema esperado bajo CSP ni resuelve system oscuro

- **Estado:** confirmado en Chrome con script y cabecera obtenidos de la aplicación de pruebas, pendiente. **Prioridad:** baja, limitada al arranque visual.
- **Ubicación:** `resources/views/app.blade.php:11`–`:20`; política de `app/Http/Middleware/AddSecurityHeaders.php:29`.

Se agrupan dos condiciones de fallo del mismo inicializador temprano, que intenta aplicar el tema antes de cargar la aplicación:

1. **CSP de producción:** la política admite scripts con nonce, pero este bloque inline no lo incorpora. Con preferencia local `dark`, Chrome bloquea su ejecución y registra una violación **`script-src-elem` / `inline`**. En esa fase la clase oscura no se aplica. Como control, añadir el nonce correcto únicamente a la copia HTML de la prueba permite ejecutar el mismo bloque y aplicar el tema, sin violaciones.
2. **Modo `system`:** sin esa restricción CSP para aislar la segunda causa, configurar preferencia local `system` y modo oscuro del sistema. El bloque solo compara el valor con `dark`, por lo que aplica **claro** y `colorScheme = light`, aunque `matchMedia` confirma que el sistema está en oscuro.

La utilidad `applyTheme` utilizada después por la aplicación sí resuelve `system` y sus cambios, según el control del navegador. El hallazgo corresponde a la **fase previa a React**: no se afirma que la interfaz permanezca siempre en claro ni se midió la duración de un posible cambio visible al cargar los módulos. El navegador de prueba no descargó la aplicación operativa; recibió únicamente el inicializador capturado y las utilidades originales necesarias para cada control.

**Evidencia:** `inicializador-csp.json` y los casos de inicialización/CSP/control de nonce de `resultados-frontend.json`.

**Corrección propuesta:** hacer compatible el inicializador con el nonce de la política activa y resolver `system` de forma coherente con la utilidad posterior. Mantener fallback cuando no se puede acceder al almacenamiento. No resolver el problema habilitando indiscriminadamente scripts inline ni debilitando la CSP.

### Ampliaciones de incidencias previas, sin aumentar su conteo

- **ERR-066, actor desactualizado:** después de autorizar la publicación, una intercalación controlada desactiva al actor en la base antes de guardar los perfiles. El controlador continúa con el usuario de la solicitud, devuelve 302 y publica la refracción 1,2. Se reutiliza el defecto de revalidación ya registrado, no una nueva incidencia. No se ejecutó concurrencia real. Evidencia: `propagacion-ERR-066-publicacion.json`.
- **ERR-086, excepciones de caché sin aislamiento:** fallar únicamente la lectura de `fiea:liquid-glass:settings`, con las demás claves disponibles, provoca **500 al abrir el login público**, porque los datos compartidos dependen de esa lectura cosmética sin fallback. Se amplía el impacto del manejo de caché ya documentado; no se añade otro identificador. Evidencia: `propagacion-ERR-086-cache-login.json`.
- **ERR-094, mutación bloqueada con respuesta compatible con éxito:** una cuenta con cambio obligatorio intenta pasar de claro a oscuro. El servidor no guarda el tema, pero después de las redirecciones entrega **200 HTML**, que Axios resuelve como éxito. La prueba del control React original con esa clase de respuesta conserva DOM y preferencia local en oscuro mientras el servidor simulado sigue en claro. Su reversión normal ante un rechazo de red sí funciona. Se confirma la ampliación del mismo protocolo de bloqueo, sin contarla otra vez. Evidencia: `propagacion-ERR-094-tema.json` y último caso de `resultados-frontend.json`.

### Límites del decimoséptimo bloque

Las pruebas de servidor usan **SQLite en memoria** y cuentas/perfiles sintéticos. La caché es de prueba, y el entorno se cambia temporalmente solo para activar ramas que no se ejecutan como `testing`. La intercalación de actor y el fallo de caché son inyecciones deterministas, expresamente identificadas.

El navegador se abrió sin interfaz, con perfiles temporales; no utilizó sesiones, cookies ni páginas del usuario. Los escenarios de `ThemeToggle` montan React y el componente real, pero simulan el contexto de página y las respuestas de Axios. Las pruebas del motor llaman su cálculo original, sin certificar rasterización, rendimiento GPU, contraste o una revisión visual completa del sistema. El nonce de control solo modifica la fixture HTML en memoria.

No se modificaron usuarios, preferencias, perfiles globales ni otros datos operativos. Tampoco se alteraron código de aplicación, dependencias, configuración persistente ni los Excel originales. La integridad y los resultados se verifican en `verification/appearance-audit/cerrar_auditoria.py`, con salida en `resumen.json`.

Este bloque añade **dos incidencias nuevas: 97 incidencias confirmadas y pendientes en total**. Con él quedan recorridos los **17 módulos identificados**; la siguiente fase propuesta es una **validación integral de extremo a extremo contra los Excel**, con datos aislados y sin confundir esta revisión con una corrección de las incidencias pendientes.


## ERR-098 — Fechas españolas del control sin normalización ni resolución

Confirmado el 2026-10-29. Prioridad alta. El control original contiene siete fechas inequívocas, como `2025!E21 = 6 de agosto 25`, que el normalizador rechaza. También existen cinco fechas con prefijo `I -` que requieren una interpretación explícita.

`ControlWorkbookParser::date()` solo admite día/mes/año con año de cuatro cifras o un serial entero. `ControlImportService::apply()` rechaza filas con errores; `AccountingImportController::apply()` no admite resolver las fechas de envío. Por tanto, incluso después de corregir ERR-001, la fila IE-25-019 queda bloqueada por su fecha Final.

Se ejecutaron los métodos originales sobre las celdas, sin modificar el libro. Las siete devuelven `Fecha inválida.`. Una fila de diagnóstico preparada desde el XML original devuelve 422 al aplicar y el intento HTTP de resolver `legacy_send_final` también devuelve 422. No se cambian versión ni pagos.

Evidencia: `ERR-098-fechas-espanolas.json`, `ERR-098-aplicacion-y-resolucion.json`, `ValidacionIntegralExcelTest.php`.

Corrección: normalización explícita y verificable de las fechas conocidas, conservación del texto de origen y resolución revisada para formatos ambiguos; nunca sustituir una fecha desconocida por la fecha actual.


## Intervención autorizada: subsanación integral

2026-10-29: el usuario autoriza corregir aplicación, contratos, seguridad y optimización, además de actualizar este registro. Las auditorías anteriores son evidencia histórica. El estado vigente y las comprobaciones se mantienen en `verification/remediation-audit/estado-incidencias.json`. Ninguna incidencia se da por resuelta por cambiar una prueba o por ocultar datos inválidos del Excel.


## ERR-099 — Dependencias PHP con avisos de seguridad vigentes

Ampliación de auditoría: 2026-10-30. Confirmado por la consulta oficial de seguridad de `composer.lock`. Evidencia anterior a la corrección: `verification/remediation-audit/dependencias-iniciales/composer-audit.json`. Actualizar versiones compatibles, volver a consultar avisos y repetir las pruebas; no suprimir los avisos del gestor.


## ERR-100 — Dependencias JavaScript con avisos de seguridad vigentes

Ampliación de auditoría: 2026-10-30. Confirmado por la consulta oficial de seguridad de `package-lock.json`. Evidencia anterior a la corrección: `verification/remediation-audit/dependencias-iniciales/npm-audit.json`. Actualizar versiones compatibles, volver a consultar avisos y repetir las pruebas; no suprimir los avisos del gestor.
