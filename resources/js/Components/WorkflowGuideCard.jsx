import { useId, useState } from 'react';
import { readPreference, writePreference } from '@/Utils/preferences';
export default function WorkflowGuideCard({ guideKey, userId, title, steps, defaultOpen = true, summary = '', result = '', bare = false }) {
  const id = useId(), key = `fiea:guide:v1:${userId}:${guideKey}`;
  const [open, setOpen] = useState(() => readPreference(key, defaultOpen ? 'open' : 'closed') !== 'closed');
  const toggle = () => { writePreference(key, open ? 'closed' : 'open'); setOpen(!open); };
  return <section className={`${bare ? 'workflow-guide-bare' : 'glass-panel lg-micro-glass'} workflow-guide p-5`} onTransitionEnd={(event) => { if (event.propertyName === 'max-height') event.currentTarget.dispatchEvent(new CustomEvent('glass:changed', { bubbles: true })); }}>
    <button type="button" className="glass-button px-4" onClick={toggle} aria-expanded={open} aria-controls={id}><span className="guide-spark" aria-hidden="true">✦</span>{open ? 'Ocultar guía' : '¿Cómo funciona esta vista?'}</button>
    {!open && summary && <p className="mt-2 text-sm text-app-muted">{summary}</p>}
    <div id={id} className={`guide-content ${open ? 'is-open' : ''}`} aria-hidden={!open} inert={!open}><h2 className="mt-4 text-lg font-semibold">{title}</h2>
      <ol className="guide-steps mt-3 grid gap-3">{steps.map((step, index) => <li key={step.id} className="glass-tile p-3"><span className="text-sm font-semibold">Paso {index + 1}</span><p className="mt-1">{step.text}</p></li>)}</ol>
      {result && <p className="mt-3 text-sm font-semibold text-app-muted">{result}</p>}
    </div>
  </section>;
}
