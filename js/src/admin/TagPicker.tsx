import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import type Stream from 'flarum/common/utils/Stream';
import classList from 'flarum/common/utils/classList';

declare const m: import('mithril').Static;

const t = (key: string) => app.translator.trans(`ernestdefoe-who-said-it.admin.settings.${key}`);

/**
 * Which tags quotes may come from: none ticked = every tag a guest can read.
 * Stored as a JSON list of tag ids; restricted tags are never used anyway.
 */
export default class TagPicker extends Component<{ stream: Stream<string> }> {
  tags: any[] = [];

  oninit(vnode: any) {
    super.oninit(vnode);
    if (!('flarum-tags' in ((globalThis as any).flarum?.extensions || {}))) return;
    this.tags = app.store.all('tags');
    app.store
      .find('tags', { page: { limit: 100 } } as any)
      .then(() => {
        this.tags = app.store.all('tags');
        m.redraw();
      })
      .catch(() => {});
  }

  selected(): number[] {
    try {
      const ids = JSON.parse(this.attrs.stream() || '[]');
      return Array.isArray(ids) ? ids.map(Number) : [];
    } catch {
      return [];
    }
  }

  toggle(id: number) {
    const sel = this.selected();
    const next = sel.includes(id) ? sel.filter((x) => x !== id) : [...sel, id];
    this.attrs.stream(JSON.stringify(next));
  }

  view() {
    if (!this.tags.length) return null;
    const sel = this.selected();
    const tags = this.tags.filter((tag) => !tag.attribute('isRestricted')).sort((a, b) => String(a.name()).localeCompare(String(b.name())));

    return (
      <div className="Form-group WsiTagPicker">
        <label>{t('tags')}</label>
        <p className="helpText">{t('tags_help')}</p>
        <div className="WsiTagPicker-list">
          {tags.map((tag) => {
            const id = Number(tag.id());
            const on = sel.includes(id);
            return (
              <button
                type="button"
                className={classList('WsiTagPill', on && 'is-on')}
                style={{ '--tag': tag.color() || 'var(--muted-color)' }}
                aria-pressed={on ? 'true' : 'false'}
                onclick={() => this.toggle(id)}
              >
                <span className="WsiTagPill-dot" />
                {tag.name()}
              </button>
            );
          })}
        </div>
      </div>
    );
  }
}
