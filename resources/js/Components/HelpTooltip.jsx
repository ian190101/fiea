import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
export default function HelpTooltip({ label = 'concepto financiero', text, formula }) {
  const id = useId();
  const trigger = useRef(null), panel = useRef(null), timer = useRef(null);
  const [open, setOpen] = useState(false);
  const [position, setPosition] = useState({ top: 16, left: 16 });
  const stopTimer = () => window.clearTimeout(timer.current);
  const show = () => { stopTimer(); setOpen(true); };
  const scheduleClose = () => {
    stopTimer();
    timer.current = window.setTimeout(() => {
      if (document.activeElement !== trigger.current) setOpen(false);
    }, 180);
  };
  useEffect(() => () => window.clearTimeout(timer.current), []);
  useLayoutEffect(() => {
    if (!open) return undefined;
    const locate = () => {
      if (!trigger.current || !panel.current) return;
      const rect = trigger.current.getBoundingClientRect(), box = panel.current.getBoundingClientRect();
      const left = Math.max(16, Math.min(rect.left, window.innerWidth - box.width - 16));
      const top = rect.bottom + 8 + box.height < window.innerHeight - 16 ? rect.bottom + 8 : Math.max(16, rect.top - box.height - 8);
      setPosition({ top, left });
    };
    locate(); window.addEventListener('resize', locate); window.addEventListener('scroll', locate, true);
    return () => { window.removeEventListener('resize', locate); window.removeEventListener('scroll', locate, true); };
  }, [open, text, formula]);
  useEffect(() => {
    if (!open) return undefined;
    const outside = (event) => { if (!trigger.current?.contains(event.target) && !panel.current?.contains(event.target)) setOpen(false); };
    const escape = (event) => { if (event.key === 'Escape') { stopTimer(); setOpen(false); } };
    document.addEventListener('pointerdown', outside); document.addEventListener('keydown', escape);
    return () => { document.removeEventListener('pointerdown', outside); document.removeEventListener('keydown', escape); };
  }, [open]);
  return <>
    <button ref={trigger} type="button" aria-label={`Ayuda: ${label}`} aria-describedby={open ? id : undefined}
      className="liquid-help-trigger ml-1 inline-flex h-11 w-11 items-center justify-center p-0 align-middle"
      onPointerEnter={(event) => { if (event.pointerType === 'mouse') show(); }} onPointerLeave={scheduleClose}
      onFocus={show} onBlur={scheduleClose} onClick={show}>
      <span className="help-micro lg-micro-glass" aria-hidden="true">?</span>
    </button>
    {open && createPortal(<div ref={panel} id={id} role="tooltip" className="glass-panel glass-help lg-micro-glass" style={position}
      onPointerEnter={stopTimer} onPointerLeave={scheduleClose}>
      <p className="font-semibold">{label}</p><p className="mt-2 leading-relaxed">{text}</p>
      {formula && <p className="mt-3 break-words font-mono text-sm">{formula}</p>}
    </div>, document.body)}
  </>;
}
