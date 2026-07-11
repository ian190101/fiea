# Validacion de flujo completo

Este modulo agrega una prueba de aceptacion para proteger el recorrido financiero principal del sistema:

1. Crear una fase de viaje sobre proyecto, equipo y contacto existentes.
2. Registrar lineas de draft budget DR y WODR.
3. Generar el PDF de draft budget en ingles y guardarlo como archivo del sistema.
4. Registrar gastos reales, subir comprobante y conservar el archivo asociado.
5. Crear invoices separados para IC y MAT.
6. Aprobar invoice IC, generar PDF, preparar correo y enviarlo.
7. Conciliar el invoice desde contabilidad.
8. Exportar el reporte financiero CSV filtrado por proyecto.

Comando de verificacion focalizada:

```bash
php artisan test --filter=FullWorkflowAcceptanceTest
```

Esta prueba no incluye backups porque dependen de binarios locales de MySQL o MariaDB. Ese comportamiento se valida en su propio modulo para evitar que el flujo financiero falle por configuracion externa del entorno.
