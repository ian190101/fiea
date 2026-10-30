import { memo, useId } from 'react';
import { formatUsd } from '@/Utils/money';
const DecimalInput = memo(function DecimalInput({ label, value, onChange, disabled, error }) {
  const id = useId();
  const localError = value !== '' && value !== null && !/^\d+(\.\d{0,4})?$/.test(value ?? '') ? 'Usa hasta cuatro decimales, por ejemplo 32.5000.' : null;
  const message = error || localError;
  return <div className="min-w-28">
    <label htmlFor={id} className="sr-only">{label}</label>
    <input id={id} type="text" inputMode="decimal" className="ios-field money w-28" value={value ?? ''}
      disabled={disabled} aria-invalid={Boolean(message)} aria-describedby={message ? `${id}-error` : undefined}
      onChange={(event) => onChange(event.target.value)} />
    {message && <p id={`${id}-error`} className="mt-1 text-sm text-red-700 dark:text-red-200">{message}</p>}
  </div>;
});
export default function DailyBudgetMatrix({ days, lines, invoiceType, stage, editable, dirty, errors = {}, onLineChange, onCellChange }) {
  const final = stage === 'final';
  return <div className="financial-scroll" tabIndex={0} role="region" aria-label="Matriz de gastos, desplazamiento horizontal">
    <table className="financial-table">
      <caption className="p-3 text-left">{invoiceType} · {final ? 'Final' : 'Initial'} · {dirty ? 'Totales pendientes de guardar' : 'Totales confirmados por el servidor'}</caption>
      <thead><tr>
        <th scope="col">Descripción / unidad</th><th scope="col">Costo unitario</th>
        {invoiceType === 'IC' ? days.map((day) => <th key={day.date} scope="col"><time dateTime={day.date}>{day.date}</time><span className="block text-xs">{day.label}</span></th>) : <th scope="col">Cantidad / recibo</th>}
        <th scope="col" className="money">Total USD</th>
      </tr></thead>
      <tbody>{lines.map((line, lineIndex) => <tr key={line.id}>
        <th scope="row" className="min-w-52 text-left"><span lang="en">{line.description_en}</span><span className="block text-sm font-normal">{line.description_es || 'Traducción pendiente'}</span><span className="block text-xs">{line.unit}</span></th>
        <td><DecimalInput label={`Costo de ${line.description_en}`} value={line.unit_cost} disabled={!editable}
          error={errors[`lines.${lineIndex}.unit_cost`]} onChange={(value) => onLineChange(line.id, 'unit_cost', value)} /></td>
        {invoiceType === 'IC' ? days.map((day) => {
          const cellIndex = line.cells.findIndex((item) => item.date === day.date), cell = line.cells[cellIndex];
          if (!cell) return <td key={day.date}>Celda pendiente de inicialización</td>;
          return <td key={day.date}>
            <DecimalInput label={`${line.description_en}, cantidad del ${day.date}`} value={cell.quantity} disabled={!editable}
              error={errors[`lines.${lineIndex}.cells.${cellIndex}.quantity`]} onChange={(value) => onCellChange(line.id, day.date, 'quantity', value)} />
            <p className="money mt-2">{dirty ? 'Pendiente' : formatUsd(cell.total)}</p>
            {final && <label className="mt-2 block text-xs">Recibo del {day.date}<input type="text" className="ios-field mt-1 w-40" maxLength={255}
              value={cell.receipt_number ?? ''} disabled={!editable} onChange={(event) => onCellChange(line.id, day.date, 'receipt_number', event.target.value)} /></label>}
          </td>;
        }) : <td>
          <DecimalInput label={`Cantidad de ${line.description_en}`} value={line.quantity} disabled={!editable}
            error={errors[`lines.${lineIndex}.quantity`]} onChange={(value) => onLineChange(line.id, 'quantity', value)} />
          {final && <label className="mt-2 block text-xs">Recibo<input type="text" className="ios-field mt-1 w-40" maxLength={255}
            value={line.receipt_number ?? ''} disabled={!editable} onChange={(event) => onLineChange(line.id, 'receipt_number', event.target.value)} /></label>}
        </td>}
        <td className="money">{dirty ? 'Pendiente' : formatUsd(line.total)}</td>
      </tr>)}</tbody>
    </table>
  </div>;
}
