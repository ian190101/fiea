/** @param {string|null|undefined} value */
export function formatUsd(value) {
  if (value === null || value === undefined) return 'Pendiente';
  if (typeof value !== 'string' || !/^-?\d+\.\d{2}$/.test(value)) return 'Importe inválido';
  const negative = value.startsWith('-');
  const absolute = negative ? value.slice(1) : value;
  const [integer, fraction] = absolute.split('.');
  return `${negative && !/^0+\.00$/.test(absolute) ? '-' : ''}$${integer.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${fraction}`;
}
/** @param {string|null|undefined} value */
export function moneySign(value) {
  if (typeof value !== 'string' || !/^-?\d+\.\d{2}$/.test(value)) return null;
  if (/^-?0+\.00$/.test(value)) return 0;
  return value.startsWith('-') ? -1 : 1;
}
