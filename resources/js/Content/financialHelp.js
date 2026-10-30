export const financialHelp = {
  initialDr: { label: 'INITIAL DR', text: 'Gastos DR previstos más contingencia. La base de contingencia IC incluye los gastos propios que el libro incluye en SUBTOTAL INITIAL EXPENSES.', formula: 'DR base + contingencia inicial del motor financiero.' },
  initialWodr: { label: 'INITIAL WODR', text: 'Servicios propios de EIA, comisión sobre servicios elegibles y Bank Fees.', formula: 'Servicios propios + comisión 5% + Bank Fees.' },
  arrangedFee: { label: 'Arranged Services Fee 5%', text: 'Solo líneas elegibles del catálogo y Misc personalizado aprobado; no toda la factura.', formula: 'Redondear(base elegible × 0.05).' },
  contingency: { label: 'Contingency 10%', text: 'Incluida en DR. IC Final usa cero salvo ajuste real; MAT aplica 10% a la base de su etapa salvo override explícito aprobado. Vacío no significa override cero.', formula: 'IC Initial: subtotal Initial Expenses × 0.10. MAT: (materiales + transporte de la etapa) × 0.10.' },
  materialFee: { label: 'Material Fee 15%', text: 'Compra y entrega, incluida en WODR; excluye contingencia y Bank Fees de su base.', formula: '(Materiales + transporte) × 0.15.' },
  balanceDue: { label: 'Balance Due', text: 'Diferencia documental; negativa no prueba dinero recibido ni crédito disponible.', formula: 'Final − Initial; si no existe Initial, Final.' },
  credit: { label: 'Crédito disponible', text: 'Saldo confirmado de efectivo, descontadas aplicaciones, reservas y devoluciones.', formula: 'Movimientos confirmados del libro de tesorería; no −Balance Due.' },
  accountingDifference: { label: 'Diferencia Conta vs Registro', text: 'Compara gasto operativo final y contabilidad. Dato faltante se muestra pendiente.', formula: 'Gasto suma final registrado − Gasto Conta.' },
  expectedFiea: { label: 'Monto en teoría FIEA', text: 'Esperado de transferencia según la regla aprobada del expediente. Revisar origen mostrado junto al valor.', formula: 'Valor esperado del servidor y regla documentada; no inferirlo del invoice.' },
};
