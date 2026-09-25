import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react'
import './Toast.css'

type Toast = { id: number; kind: 'success' | 'error' | 'info'; text: string }
type ToastApi = { show: (text: string, kind?: Toast['kind']) => void; success: (text: string) => void; error: (text: string) => void }

const ToastContext = createContext<ToastApi | null>(null)

/** Lightweight feedback toasts (aria-live) for save / delete / error confirmations. */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<Toast[]>([])
  const show = useCallback((text: string, kind: Toast['kind'] = 'info') => {
    const id = Date.now() + Math.random()
    setItems((t) => [...t, { id, kind, text }])
    setTimeout(() => setItems((t) => t.filter((x) => x.id !== id)), 3500)
  }, [])
  const api = useMemo<ToastApi>(() => ({ show, success: (t) => show(t, 'success'), error: (t) => show(t, 'error') }), [show])
  return (
    <ToastContext.Provider value={api}>
      {children}
      <div className="toasts" aria-live="polite">
        {items.map((t) => <div key={t.id} role="status" className={`toast toast--${t.kind}`}>{t.text}</div>)}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast() {
  const ctx = useContext(ToastContext)
  if (!ctx) throw new Error('useToast must be used inside ToastProvider')
  return ctx
}
