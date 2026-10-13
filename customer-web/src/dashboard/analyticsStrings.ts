/**
 * Restaurant Dashboard — analytics on the backend (Module 38). English source table, spread into `dashStrings`.
 * Plain business wording. The figures are "updated about every 5 minutes": no string here calls them anything that
 * suggests this very moment, and none grades a menu item or a restaurant.
 */
export const analyticsStrings: Record<string, string> = {
  'dash.ra.lead': "Your own restaurant's figures, for the locations you may read. Sales are what customers paid; your earnings are a separate figure.",
  'dash.ra.tabs': 'Analytics sections',
  'dash.ra.tab.overview': 'Overview', 'dash.ra.tab.orders': 'Orders', 'dash.ra.tab.menu': 'Menu', 'dash.ra.tab.operations': 'Operations', 'dash.ra.tab.routes': 'Routes', 'dash.ra.tab.reviews': 'Reviews', 'dash.ra.tab.earnings': 'Earnings', 'dash.ra.tab.exports': 'Exports',

  'dash.ra.filters': 'Location and period',
  'dash.ra.location': 'Location', 'dash.ra.location.all': 'All my locations', 'dash.ra.location.selected': 'Selected location',
  'dash.ra.period': 'Period',
  'dash.ra.period.today': 'Today', 'dash.ra.period.yesterday': 'Yesterday', 'dash.ra.period.last_7_days': 'Last 7 days', 'dash.ra.period.last_30_days': 'Last 30 days', 'dash.ra.period.this_month': 'This month', 'dash.ra.period.previous_month': 'Previous month', 'dash.ra.period.custom': 'Custom',
  'dash.ra.period.from': 'From', 'dash.ra.period.to': 'To',
  'dash.ra.period.needBoth': 'Choose a first and a last day to see the figures of a custom period.',
  'dash.ra.period.order': 'The first day must not be after the last day.',
  'dash.ra.period.tooLong': 'A period covers at most {days} days.',
  'dash.ra.period.hint': 'Both days are included. A period covers at most {days} days.',

  'dash.ra.meta.period': '{from} – {to} ({days} day(s))', 'dash.ra.meta.zone': 'Time zone {zone}',
  'dash.ra.meta.currency': 'Currency {currency}', 'dash.ra.meta.currencies': 'Currencies {currencies} — shown separately, never added together',
  'dash.ra.meta.freshness': '{label} · figures as of {time}',
  'dash.ra.meta.stale': 'These figures may be out of date: the last update is older than usual.',

  'dash.ra.noData': 'No data for this period.', 'dash.ra.insufficient': 'Insufficient data',
  'dash.ra.restricted': 'You do not have access to this figure.', 'dash.ra.restricted.earnings': 'You need earnings access to see this figure',
  'dash.ra.mixed': 'One amount per currency — they are never added together.',
  'dash.ra.rate.of': '{n} of {d}', 'dash.ra.rating': '{value} / 5',
  'dash.ra.cmp.new': 'New — no earlier figure to compare', 'dash.ra.cmp.pct': '{change}% vs the previous period', 'dash.ra.cmp.points': '{change} percentage points vs the previous period',
  'dash.ra.dur.s': '{s} s', 'dash.ra.dur.m': '{m} min', 'dash.ra.dur.ms': '{m} min {s} s', 'dash.ra.dur.h': '{h} h', 'dash.ra.dur.hm': '{h} h {m} min', 'dash.ra.dur.milli': '{ms} ms',
  'dash.ra.bucket.hour': 'per hour', 'dash.ra.bucket.day': 'per day', 'dash.ra.bucket.week': 'per week (weeks start on Monday)', 'dash.ra.bucket.month': 'per month',

  'dash.ra.def.button': 'Definition', 'dash.ra.def.show': 'Definition of {name}', 'dash.ra.def.source': 'Source', 'dash.ra.def.numerator': 'Counted', 'dash.ra.def.denominator': 'Out of', 'dash.ra.def.exclusions': 'Left out',
  'dash.ra.def.loading': 'Loading the definition…', 'dash.ra.def.missing': 'The definition of this figure is not available right now.',

  'dash.ra.error.title': 'Unable to load these figures', 'dash.ra.error.failed': 'A temporary problem stopped us from loading this. Please try again.',
  'dash.ra.error.offline': 'Cannot reach FoodOnTheGo right now. Check your connection and try again.', 'dash.ra.error.rateLimited': 'Too many requests. Please wait a moment and try again.',
  'dash.ra.error.forbiddenTitle': 'No access to these figures', 'dash.ra.error.forbidden': 'You need analytics access for this location to see these figures.',

  'dash.ra.chart.axes': '{unit} · {bucket} · {from} – {to} · time zone {zone}',
  'dash.ra.chart.unit.orders': 'Number of orders', 'dash.ra.chart.unit.reviews': 'Number of reviews', 'dash.ra.chart.unit.money': 'Amount in {currency}',
  'dash.ra.chart.placed': 'Orders placed', 'dash.ra.chart.completed': 'Orders completed', 'dash.ra.chart.sales': 'Sales of completed orders',
  'dash.ra.chart.salesMixed': 'Several currencies — no single money line',
  'dash.ra.col.bucket.hour': 'Hour', 'dash.ra.col.bucket.day': 'Day', 'dash.ra.col.bucket.week': 'Week starting', 'dash.ra.col.bucket.month': 'Month',
  'dash.ra.col.count': 'Count', 'dash.ra.col.orders': 'Orders', 'dash.ra.col.reason': 'Reason', 'dash.ra.col.location': 'Location', 'dash.ra.col.currency': 'Currency', 'dash.ra.col.placed': 'Orders placed', 'dash.ra.col.completed': 'Completed',
  'dash.ra.col.sales': 'Sales', 'dash.ra.col.rating': 'Average rating', 'dash.ra.col.item': 'Item', 'dash.ra.col.views': 'Views', 'dash.ra.col.cartAdds': 'Add-to-cart', 'dash.ra.col.units': 'Units sold', 'dash.ra.col.share': 'Share of sales',
  'dash.ra.col.mode': 'When', 'dash.ra.col.method': 'Method', 'dash.ra.col.leadTime': 'Average lead time', 'dash.ra.col.detour': 'Detour', 'dash.ra.col.stars': 'Rating', 'dash.ra.col.reviews': 'Reviews',

  'dash.ra.status.title': 'Order status', 'dash.ra.status.sub': 'Where the orders placed in this period stand now.',
  'dash.ra.customers.title': 'Customers', 'dash.ra.customers.sub': 'Counts only — there is never a list of customers.',
  'dash.ra.customers.ordering': 'Customers who completed an order', 'dash.ra.customers.firstTime': 'First time here', 'dash.ra.customers.returning': 'Returning', 'dash.ra.customers.perCustomer': 'Orders per customer',
  'dash.ra.customers.suppressed': 'Too few customers to show a split',
  'dash.ra.locations.title': 'By location', 'dash.ra.locations.sub': 'Your own locations, each in its own currency.',

  'dash.ra.orders.counts': 'Orders in this period', 'dash.ra.orders.rates': 'Rates and order value',
  'dash.ra.durations.title': 'How long each step takes', 'dash.ra.durations.sub': 'Average, median and 90th percentile are three different figures. Nine in ten orders were at or under the 90th percentile.',
  'dash.ra.durations.caption': 'Durations of the steps of an order', 'dash.ra.durations.step': 'Step', 'dash.ra.durations.average': 'Average', 'dash.ra.durations.median': 'Median', 'dash.ra.durations.p90': '90th percentile', 'dash.ra.durations.count': 'Orders measured',
  'dash.ra.rejections.title': 'Rejected orders', 'dash.ra.rejections.sub': 'The reasons recorded when an order was rejected.',

  'dash.ra.menu.behaviour': 'From what customers looked at', 'dash.ra.menu.orders': 'From completed orders',
  'dash.ra.menu.items': 'Items', 'dash.ra.menu.items.sub': 'Views and add-to-cart come from one source, units and sales from another. Read them side by side, not as one figure.',

  'dash.ra.estimate.title': 'Estimated and actual preparation', 'dash.ra.estimate.sub': 'The time customers were told against the time it took.',
  'dash.ra.estimate.estimated': 'Average estimate', 'dash.ra.estimate.actual': 'Average actual time', 'dash.ra.estimate.within': 'Ready within the estimate',
  'dash.ra.peak.title': 'Peak times', 'dash.ra.peak.sub': "Orders placed by weekday and hour, in your restaurant's time zone ({zone}). Weeks start on Monday.",
  'dash.ra.peak.caption': 'Orders placed by weekday and hour of the day', 'dash.ra.peak.day': 'Day', 'dash.ra.peak.hour': 'Hour starting {hour}',
  'dash.ra.pickup.title': 'Pickup', 'dash.ra.pickup.sub': 'How customers chose to collect, and pickup times that could not be offered.',
  'dash.ra.pickup.modes': 'ASAP and scheduled', 'dash.ra.pickup.methods': 'Pickup methods', 'dash.ra.pickup.refusals': 'Pickup times refused',
  'dash.ra.pickup.capacity': 'Capacity per pickup slot', 'dash.ra.pickup.slotsWithOrders': 'Slots with orders', 'dash.ra.pickup.slotsFull': 'Slots that were full', 'dash.ra.pickup.units': 'Orders against capacity',

  'dash.ra.routes.bands': 'Detour of travellers who ordered', 'dash.ra.routes.bands.sub': 'How far off their way your orders from journeys were, in bands. No map and no route is shown.',
  'dash.ra.routes.bandMinutes': '{band} min', 'dash.ra.routes.suppressed': 'Too few orders to show',

  'dash.ra.reviews.distribution': 'Ratings', 'dash.ra.reviews.distribution.sub': 'Published reviews by number of stars.', 'dash.ra.reviews.stars': '{n} star(s)', 'dash.ra.reviews.series': 'Reviews published',

  'dash.ra.earnings.restricted': 'You need earnings access to see this',
  'dash.ra.earnings.open': 'Open the Earnings page', 'dash.ra.earnings.series': 'Net earnings per day ({currency})', 'dash.ra.earnings.definitions.sub': 'The definitions FoodOnTheGo uses for every figure on this tab.',

  'dash.ra.export.title': 'Generate an export', 'dash.ra.export.sub': 'A CSV file of your own figures. It can be downloaded for {hours} hours after it is ready.', 'dash.ra.export.sub.plain': 'A CSV file of your own figures.',
  'dash.ra.export.report': 'Report', 'dash.ra.export.report.orders': 'Orders', 'dash.ra.export.report.menu_items': 'Menu items',
  'dash.ra.export.generate': 'Generate export', 'dash.ra.export.generating': 'Asking…', 'dash.ra.export.scope': 'It will cover: {scope}',
  'dash.ra.export.requested': 'The {label} export is being prepared. It appears below when it is ready.',
  'dash.ra.export.forbidden': 'You need export access to do this.',
  'dash.ra.export.list': 'Your exports', 'dash.ra.export.list.sub': 'Only the exports you asked for yourself.', 'dash.ra.export.refresh': 'Refresh',
  'dash.ra.export.empty': 'No exports yet', 'dash.ra.export.empty.text': 'Choose a report above and generate your first export.',
  'dash.ra.export.col.report': 'Report', 'dash.ra.export.col.period': 'Period', 'dash.ra.export.col.requested': 'Asked for', 'dash.ra.export.col.status': 'Status', 'dash.ra.export.col.rows': 'Rows', 'dash.ra.export.col.expires': 'Expires',
  'dash.ra.export.status.QUEUED': 'Queued', 'dash.ra.export.status.PROCESSING': 'Processing', 'dash.ra.export.status.READY': 'Ready', 'dash.ra.export.status.FAILED': 'Failed', 'dash.ra.export.status.EXPIRED': 'Expired',
  'dash.ra.export.failed.tooManyRows': 'Too many rows — choose a shorter period.', 'dash.ra.export.failed.other': 'It could not be prepared. Please ask for it again.',
  'dash.ra.export.download': 'Download', 'dash.ra.export.downloadOf': 'Download {label}, {from} to {to}', 'dash.ra.export.downloaded': 'Downloaded {name}.',
  'dash.ra.export.polling': 'An export is being prepared — this list is checked again every few seconds.',

  'dash.ra.home.today': 'Today so far', 'dash.ra.home.lead': 'Today so far at {restaurant}.',
  'dash.ra.home.restricted': "You need analytics access for this location to see today's figures.",
  'dash.ra.home.ordersByHour': 'Orders placed today', 'dash.ra.home.shortcuts': 'Go to',
  'dash.ra.home.orders': 'Open the order queue', 'dash.ra.home.analytics': 'Open analytics for this location', 'dash.ra.home.menu': 'Open the menu', 'dash.ra.home.earnings': 'Open earnings',
}
