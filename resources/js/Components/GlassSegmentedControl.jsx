import { useId } from 'react';
export default function GlassSegmentedControl({ label, options, value, onChange, disabled = false }) {
  const name = useId();
  const index = Math.max(0, options.findIndex((option) => option.value === value));
  if (!options.length) return null;
  return <fieldset disabled={disabled} className="min-w-0">
    <legend className="mb-2 text-sm font-semibold">{label}</legend>
    <div className="overflow-x-auto pb-2"><div className="glass-pill relative grid min-w-max p-1" style={{ gridTemplateColumns: `repeat(${options.length}, minmax(7rem, 1fr))` }}>
      <span aria-hidden="true" className="glass-segment-indicator pointer-events-none absolute bottom-1 left-1 top-1 rounded-full bg-white/90 shadow-sm dark:bg-slate-700"
        style={{ width: `calc((100% - .5rem) / ${options.length})`, transform: `translateX(${index * 100}%)` }} />
      {options.map((option) => <label key={option.value} className="relative cursor-pointer rounded-full px-4 py-3 text-center text-sm font-semibold focus-within:outline focus-within:outline-2">
        <input type="radio" name={name} className="sr-only" value={option.value} checked={value === option.value}
          onChange={() => onChange(option.value)} disabled={disabled || option.disabled} />{option.label}
      </label>)}
    </div></div>
  </fieldset>;
}
