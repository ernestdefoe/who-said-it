import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import type Stream from 'flarum/common/utils/Stream';
import classList from 'flarum/common/utils/classList';
import Icon from 'flarum/common/components/Icon';

const t = (key: string) => app.translator.trans(`ernestdefoe-who-said-it.admin.settings.${key}`);

/** Guests and Members are everyone: listing either would empty the quiz. */
const EVERYONE = ['2', '3'];

/**
 * "Never quote members of these groups": bots, feed importers, staff. Stored
 * as a JSON list of group ids; none ticked by default.
 */
export default class GroupPicker extends Component<{ stream: Stream<string> }> {
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
    this.attrs.stream(JSON.stringify(sel.includes(id) ? sel.filter((x) => x !== id) : [...sel, id]));
  }

  view() {
    const sel = this.selected();
    const groups = app.store.all<any>('groups').filter((group) => !EVERYONE.includes(group.id()!));

    return (
      <div className="Form-group WsiTagPicker">
        <label>{t('groups')}</label>
        <p className="helpText">{t('groups_help')}</p>
        <div className="WsiTagPicker-list">
          {groups.map((group) => {
            const id = Number(group.id());
            const on = sel.includes(id);
            return (
              <button
                type="button"
                className={classList('WsiTagPill', on && 'is-on')}
                style={{ '--tag': group.color() || 'var(--muted-color)' }}
                aria-pressed={on ? 'true' : 'false'}
                onclick={() => this.toggle(id)}
              >
                {group.icon() ? <Icon name={group.icon()} /> : <span className="WsiTagPill-dot" />}
                {group.namePlural()}
              </button>
            );
          })}
        </div>
      </div>
    );
  }
}
