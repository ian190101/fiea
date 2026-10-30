const make = (texts) => texts.map((text, index) => ({ id: String(index + 1), text }));
export const workflows = {
  budget: make(['Completa equipo, proyecto y fechas.', 'Introduce cantidades Initial en la matriz.', 'Revisa DR, WODR y comisiones.', 'Registra gastos y recibos Final.', 'Compara cierre y valida saldo con tesorería.']),
  invoices: make(['Revisa presupuesto aprobado.', 'Comprueba destinatario y llegada.', 'Emite Initial con el plazo aplicable.', 'Revisa gastos Final.', 'Emite Final; final-only no exige Initial ficticio.']),
  treasury: make(['Registra pago confirmado del capítulo a EIA.', 'Identifica invoice y referencia.', 'Registra transferencia EIA a FIEA.', 'Compara esperado y recibido.', 'Aplica créditos solo con saldo confirmado.']),
  accounting: make(['Selecciona año y expediente.', 'Comprueba gasto operativo Final.', 'Carga gasto de contabilidad.', 'Revisa diferencia y documentos.', 'Resuelve diferencias y solicita cierre autorizado.']),
};
