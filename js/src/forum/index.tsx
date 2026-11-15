import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Switch from 'flarum/common/components/Switch';
import type Mithril from 'mithril';
import { t } from '../common/text';
import { placed } from '../common/fofWidgets';
import { saveMe, showError, today } from './state';
import TodayCard from './components/TodayCard';
import { EXT, KEY, registerWidgets } from './widgets';

export { default as extend } from './extend';

declare const m: import('mithril').Static;

/*
 * forum.js is loaded on every page, so it only wires things up: the sidebar
 * link and card, two settings switches and the widgets here; the route, the
 * reminder and the record model in extend.ts. The quiz page is its own chunk.
 */

app.initializers.add('ernestdefoe/who-said-it', () => {
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
    // The switch shows the stored choice again (it never changed), and the member is told why.
    .catch(showError)
    .finally(() => {
      page[flag] = false;
      m.redraw();
    });
}
