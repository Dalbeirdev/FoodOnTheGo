import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../i18n/format.dart';
import '../i18n/strings.dart';
import '../state/cart_state.dart';

/// After sign-in two carts of DIFFERENT restaurants may meet: the one built as a guest and the one saved in the
/// account (Module 27). They are never merged and neither is dropped quietly — whatever screen the customer is on,
/// this asks which one to keep and tells the backend the answer.
class CartMergePrompt extends StatelessWidget {
  const CartMergePrompt({super.key, required this.child});
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final cart = context.watch<CartState>();
    final conflict = cart.mergeConflict;
    if (conflict == null) return child;
    String keep(CartSummary s) => S.t('cart.merge.keep', {'restaurant': s.restaurantName, 'count': s.itemCount, 'total': formatMoney(s.subtotalMinor, s.currency)});
    return Stack(children: [
      child,
      const ModalBarrier(dismissible: false, color: Color(0x8A000000)),
      Center(
        child: Material(
          type: MaterialType.transparency,
          child: Semantics(
            identifier: 'cart-merge',
            scopesRoute: true,
            namesRoute: true,
            explicitChildNodes: true,
            label: S.t('cart.merge.title'),
            child: Container(
              margin: const EdgeInsets.all(24),
              padding: const EdgeInsets.all(20),
              constraints: const BoxConstraints(maxWidth: 420),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(18)),
              child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Text(S.t('cart.merge.title'), style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800)),
                const SizedBox(height: 8),
                Text(S.t('cart.merge.text'), style: const TextStyle(color: Brand.grey, fontSize: 14)),
                const SizedBox(height: 16),
                Semantics(identifier: 'cart-merge-guest', child: FilledButton(onPressed: () => cart.resolveMerge('keep_guest'), child: Text(keep(conflict.guest), textAlign: TextAlign.center))),
                const SizedBox(height: 8),
                Semantics(identifier: 'cart-merge-account', child: OutlinedButton(onPressed: () => cart.resolveMerge('keep_account'), child: Text(keep(conflict.account), textAlign: TextAlign.center))),
              ]),
            ),
          ),
        ),
      ),
    ]);
  }
}
