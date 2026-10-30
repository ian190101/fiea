# Evidencias de validación

El repositorio conserva el informe final, el estado de incidencias, resultados JUnit y resúmenes de comprobación. La validación ordinaria reproducible se ejecuta con `vendor/bin/phpunit`; las ocho pruebas de utilidades frontend se ejecutan con `node --test tests/Frontend/*.test.mjs`.

Las extracciones completas de los Excel, sus derivados, las bases locales y los artefactos de diagnóstico detallados permanecen en el entorno local y no forman parte del código publicado. Algunas rutas del historial describen esa evidencia local. Los originales deben obtenerse del propietario para repetir el contraste documental completo.

El [informe de cierre](remediation-audit/auditoria-final.md) precisa las comprobaciones realizadas y sus límites. La compilación de producción y las pruebas ordinarias también se ejecutan en GitHub Actions al actualizar `main`.
