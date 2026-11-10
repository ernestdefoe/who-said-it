import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import classList from 'flarum/common/utils/classList';
import { t } from '../../common/text';
import { span, today } from '../state';

/**
 * "Today's quiz" in the sidebar: five pips that fill in as the member plays,
 * their streak, and one button. Drawn from the forum payload: no request.
 */
export default class TodayCard extends Component<{ flat?: boolean }> {
  view() {
    const state = today();
    if (!state) return null;

    const me = state.me;
    const count = state.count || 5;
    const grid = (me?.grid || '').padEnd(count, '-');
    const started = !!me && me.answered > 0;
    const finished = !!me?.finished;

    let action;
    if (!state.count) action = t('forum.card.soon');
    else if (finished) action = t('forum.card.done', { score: me!.score, correct: me!.correct, count });
    else if (started) action = t('forum.card.continue', { done: me!.answered, count });
    else action = t('forum.card.play');

    return (
      <Link href={app.route('who-said-it')} className={classList('WsiCard', this.attrs.flat && 'WsiCard--flat', finished && 'is-finished')}>
        <span className="WsiCard-mark" aria-hidden="true">
          “
        </span>
        <span className="WsiCard-head">
          <span className="WsiCard-title">{t('forum.card.title')}</span>
          {me && me.streak > 0 ? (
            <span className="WsiCard-streak" title={extract(t('forum.card.streak', { count: me.streak }))}>
              <Icon name="fas fa-fire" /> {me.streak}
            </span>
          ) : null}
        </span>
        <span className="WsiCard-lead">
          {finished ? t('forum.card.next', { time: span(Date.parse(state.nextAt) - Date.now()) }) : t('forum.card.lead')}
        </span>
        <span className="WsiPips" aria-hidden="true">
          {grid
            .slice(0, count)
            .split('')
            .map((letter) => (
              <span className={`WsiPip WsiPip--${letter === '-' ? 'open' : letter}`} />
            ))}
        </span>
        <span className="WsiCard-action">
          {action}
          {!finished && state.count ? <Icon name="fas fa-arrow-right" /> : null}
        </span>
      </Link>
    );
  }
}

function extract(text: any): string {
  return Array.isArray(text) ? text.join('') : String(text);
}
