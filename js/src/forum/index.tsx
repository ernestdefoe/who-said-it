import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Switch from 'flarum/common/components/Switch';
import type Mithril from 'mithril';
import { t } from '../common/text';
import { placed } from '../common/fofWidgets';
import { saveMe, today } from './state';
import TodayCard from './components/TodayCard';
import { ReminderNotification } from './notifications';
import { EXT, KEY, registerWidgets } from './widgets';

export { default as extend } from './extend';

declare const m: import('mithril').Static;

/*
 * forum.js is loaded on every page, so it only wires things up: the route,
 * the sidebar link and card, two settings switches, the widgets and the
 * reminder. The quiz page is its own chunk.
 */

app.initializers.add('ernestdefoe/who-said-it', () => {
  app.routes['who-said-it'] = {
    path: '/who-said-it',
    component: () => import('./components/WhoSaidItPage').then(async (page) => (await page.loadCore(), page)),
  };

  app.notificationComponents.whoSaidItReminder = ReminderNotification;

  // String form: these components live in lazy chunks, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<Mithril.Children>) {
    if (!today() || !app.forum.attribute('whoSaidItNav')) return;
    items.add('who-said-it', LinkButton.component({ href: app.route('who-said-it'), icon: 'fas fa-quote-left' }, t('forum.nav')), 78);
  });

  // The card under the sidebar's links — not on the quiz page itself, and not where FoF's layout already places it.
  extend('flarum/forum/components/IndexSidebar', 'items', function (items: ItemList<Mithril.Children>) {
    if (!today() || placed(app, EXT, KEY) || m.route.get()?.split('?')[0] === '/who-said-it') return;
    items.add('who-said-it', <TodayCard />, -20);
  });

  extend('flarum/forum/components/SettingsPage', 'privacyItems', function (this: any, items: ItemList<Mithril.Children>) {
    const me = today()?.me;
    if (!me) return;
    items.add(
      'whoSaidItQuotable',
      <Switch state={!me.optedOut} loading={this.wsiQuoteSaving} onchange={(on: boolean) => save(this, 'wsiQuoteSaving', { optedOut: !on })}>
        {t('forum.settings.quotable')}
      </Switch>,
      50
    );
  });

  extend('flarum/forum/components/SettingsPage', 'notificationsItems', function (this: any, items: ItemList<Mithril.Children>) {
    const state = today();
    if (!state?.me || !state.canPlay) return;
    items.add(
      'whoSaidItRemind',
      <Switch state={state.me.remind} loading={this.wsiRemindSaving} onchange={(on: boolean) => save(this, 'wsiRemindSaving', { remind: on })}>
        {t('forum.settings.remind')}
      </Switch>,
      50
    );
  });

  // After sibling extensions (Bespoke) have set up their registries.
  setTimeout(() => registerWidgets(), 0);
});

function save(page: any, flag: string, body: { remind?: boolean; optedOut?: boolean }) {
  page[flag] = true;
  saveMe(body)
    .catch(() => {})
    .finally(() => {
      page[flag] = false;
      m.redraw();
    });
}
