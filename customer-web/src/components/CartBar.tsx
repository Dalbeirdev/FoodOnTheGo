import { Link } from 'react-router-dom'
import { useCart } from '../cart/CartContext'
import { inr } from '../data/menu'
import { ArrowRightIcon } from './Icons'
import './CartBar.css'

const CartIcon = () => (
  <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    <path d="M3 4h2l2.5 11h11L21 7H7" /><circle cx="9" cy="20" r="1.5" /><circle cx="17" cy="20" r="1.5" />
  </svg>
)

export default function CartBar() {
  const { count, total } = useCart()
  if (count === 0) return null
  return (
    <div className="cart-bar" role="status">
      <div className="cart-bar__inner">
        <span className="cart-bar__icon"><CartIcon /><b>{count}</b></span>
        <span className="cart-bar__text"><b>{count} {count === 1 ? 'item' : 'items'} in Cart</b>{inr(total)}</span>
        <Link to="/cart" className="btn btn--primary cart-bar__btn">View Cart <ArrowRightIcon size={18} /></Link>
      </div>
    </div>
  )
}
