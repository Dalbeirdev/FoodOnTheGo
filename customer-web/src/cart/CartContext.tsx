import { createContext, useContext, useMemo, useState, type ReactNode } from 'react'

export type CartLine = {
  key: string
  itemId: string
  restaurantId: string
  name: string
  detail?: string
  unitPrice: number
  qty: number
  image?: string
  fallback?: string
}

type CartApi = {
  lines: CartLine[]
  count: number
  total: number
  note: string
  setNote: (note: string) => void
  add: (line: Omit<CartLine, 'qty'>, qty?: number) => void
  remove: (key: string) => void
  removeLine: (key: string) => void
  clear: () => void
  qtyOf: (itemId: string) => number
}

const CartContext = createContext<CartApi | null>(null)

export function CartProvider({ children }: { children: ReactNode }) {
  const [lines, setLines] = useState<CartLine[]>([])
  const [note, setNote] = useState('')

  const api = useMemo<CartApi>(() => ({
    lines,
    note,
    setNote,
    count: lines.reduce((a, l) => a + l.qty, 0),
    total: lines.reduce((a, l) => a + l.qty * l.unitPrice, 0),
    add: (line, qty = 1) =>
      setLines((ls) => {
        const i = ls.findIndex((l) => l.key === line.key)
        if (i === -1) return [...ls, { ...line, qty }]
        const n = [...ls]
        n[i] = { ...n[i], qty: n[i].qty + qty }
        return n
      }),
    remove: (key) =>
      setLines((ls) => {
        const i = ls.findIndex((l) => l.key === key)
        if (i === -1) return ls
        const n = [...ls]
        if (n[i].qty > 1) n[i] = { ...n[i], qty: n[i].qty - 1 }
        else n.splice(i, 1)
        return n
      }),
    removeLine: (key) => setLines((ls) => ls.filter((l) => l.key !== key)),
    clear: () => { setLines([]); setNote('') },
    qtyOf: (itemId) => lines.filter((l) => l.itemId === itemId).reduce((a, l) => a + l.qty, 0),
  }), [lines, note])

  return <CartContext.Provider value={api}>{children}</CartContext.Provider>
}

export function useCart() {
  const ctx = useContext(CartContext)
  if (!ctx) throw new Error('useCart must be used inside CartProvider')
  return ctx
}
