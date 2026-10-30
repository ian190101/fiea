import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
export function useUnsavedChangesGuard(isDirty) {
  const dirty = useRef(isDirty); dirty.current = isDirty;
  const bypass = useRef(false), [pending, setPending] = useState(null);
  useEffect(() => {
    const unload = (event) => { if (dirty.current) { event.preventDefault(); event.returnValue = ''; } };
    window.addEventListener('beforeunload', unload);
    const remove = router.on('before', (event) => {
      if (bypass.current) { bypass.current = false; return; }
      const visit = event.detail.visit;
      if (!dirty.current || visit.method.toLowerCase() !== 'get') return;
      setPending(() => () => router.get(visit.url, visit.data, { preserveScroll: visit.preserveScroll, preserveState: visit.preserveState, replace: visit.replace }));
      return false;
    });
    return () => { window.removeEventListener('beforeunload', unload); remove(); };
  }, []);
  return {
    request: (action) => { if (!dirty.current) action(); else setPending(() => action); },
    dialogProps: {
      open: pending !== null, title: 'Hay cambios sin guardar',
      description: 'Cancela para guardar o continúa sin guardar. Si vuelves mediante el historial, la edición puede recuperarse como pendiente.', confirmLabel: 'Continuar sin guardar',
      onCancel: () => setPending(null),
      onConfirm: () => { const action = pending; setPending(null); bypass.current = true; action?.(); queueMicrotask(() => { bypass.current = false; }); },
    },
  };
}
