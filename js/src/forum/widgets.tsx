import app from 'flarum/forum/app';
import { fof, hasFofWidgets, placed } from '../common/fofWidgets';
import { t } from '../common/text';
import { today } from './state';
import TodayCard from './components/TodayCard';

declare const m: import('mithril').Static;

export const EXT = 'ernestdefoe-who-said-it';
export const KEY = 'whoSaidItToday';

/**
 * The card as a widget, for Bespoke and fof/forum-widgets-core — resolved at
 * runtime, never imported, so the bundle is the same whether either is
 * installed. FoF's is opt-in: shown only where the admin placed it.
 */
export function registerWidgets(): void {
  const g = globalThis as any;

  if (g.flarum?.extensions && 'ernestdefoe-bespoke' in g.flarum.extensions) {
    g.BespokeWidgetQueue = g.BespokeWidgetQueue || [];
    g.BespokeWidgetQueue.push({
      type: 'who-said-it-today',
      // 🚨 A translation KEY: Bespoke resolves it, and calls string methods on it.
      label: 'ernestdefoe-who-said-it.forum.card.title',
      icon: 'fas fa-quote-left',
      zones: ['sidebar', 'above-list', 'below-list', 'footer'],
      schema: [],
      component: { view: () => m(TodayCard, { flat: true }) },
    });
  }

  if (hasFofWidgets() && (app as any).widgets) {
    const Widgets = fof('common/extend/Widgets');
    const WidgetBase = fof('common/components/Widget') as { new (...args: any[]): any } | undefined;
    if (!Widgets || !WidgetBase) return;

    class TodayFofWidget extends WidgetBase {
      className() {
        return 'WsiFofWidget FofWidgets-Widget--flat';
      }
      header() {
        return null;
      }
      icon() {
        return 'fas fa-quote-left';
      }
      title() {
        return t('forum.card.title');
      }
      content() {
        return m(TodayCard, { flat: true });
      }
    }

    new Widgets()
      .add({
        key: KEY,
        component: TodayFofWidget,
        // 🚨 Opt-in: only where the admin placed it in FoF's layout editor.
        isDisabled: () => !placed(app, EXT, KEY) || !today(),
        isUnique: true,
        placement: 'start_top',
        position: 0,
      })
      .extend(app, EXT);

    m.redraw();
  }
}
