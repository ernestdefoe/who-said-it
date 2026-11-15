import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import Avatar from 'flarum/common/components/Avatar';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { t } from '../../common/text';
import { dayLabel, user, type PageData, type Reveal } from '../api';
import { showError } from '../state';
import type Player from '../models/Player';

declare const m: import('mithril').Static;

interface Row {
  user: number;
  main: number;
  sub: Mithril.Children;
  grid: string | null;
}

/** A link to a post on this forum opens in the app; anything else as a link would. */
export function followPost(e: MouseEvent, url: string): void {
  if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
  const base = app.forum.attribute<string>('baseUrl');
  if (url.indexOf(base) !== 0) return;
  e.preventDefault();
  m.route.set(url.slice(base.length) || '/');
}

/** A quote with its answer, as a compact row: today's recap and yesterday's list. */
export function recapRow(r: Reveal, mine: boolean): Mithril.Children {
  if (r.withdrawn) {
    return (
      <div className="WsiRecapRow is-withdrawn">
        <span className="WsiRecapRow-n">{r.position}</span>
        <p className="WsiRecapRow-quote">{t('forum.recap.withdrawn')}</p>
      </div>
    );
  }

  const author = user(r.author);
  let result: Mithril.Children = null;
  if (mine) {
    result = (
      <span className="WsiRecapRow-result">
        <Icon name={r.correct ? 'fas fa-check' : 'fas fa-xmark'} />{' '}
        {r.correct ? t('forum.stage.points', { points: r.points }) : t('forum.recap.missed')}
      </span>
    );
  } else if (r.percent !== null && r.percent !== undefined) {
    result = <span className="WsiRecapRow-result">{t('forum.recap.percent', { percent: r.percent })}</span>;
  }

  return (
    <div className={classList('WsiRecapRow', mine && (r.correct ? 'is-right' : 'is-wrong'))}>
      <span className="WsiRecapRow-n">{r.position}</span>
      <div className="WsiRecapRow-body">
        <p className="WsiRecapRow-quote">“{r.excerpt}”</p>
        <div className="WsiRecapRow-meta">
          <span className="WsiRecapRow-author">
            <Avatar user={author} className="Avatar--small" /> {author ? author.displayName() : t('forum.stage.deleted')}
          </span>
          {result}
          {r.post ? (
            <a className="WsiRecapRow-link" href={r.post.url} onclick={(e: MouseEvent) => followPost(e, r.post!.url)}>
              {t('forum.recap.read')} <Icon name="fas fa-arrow-right" />
            </a>
          ) : null}
        </div>
      </div>
    </div>
  );
}

/** Yesterday's quotes with their answers and how many got each right. */
export function yesterdayList(data: PageData): Mithril.Children {
  const y = data.yesterday;

  return (
    <section className="WsiYesterday">
      <h3 className="WsiSection-title">
        <Icon name="fas fa-clock-rotate-left" /> {t('forum.yesterday.title')}
        {y ? <span className="WsiSection-date">{dayLabel(y.date)}</span> : null}
      </h3>
      {y && y.questions.length ? (
        <div className="WsiRecap">{y.questions.map((r) => recapRow(r, false))}</div>
      ) : (
        <p className="WsiBoard-empty">{t('forum.yesterday.empty')}</p>
      )}
    </section>
  );
}

/**
 * The leaderboard: today's (from the page) and all time (the
 * `who-said-it-players` resource, fetched the first time its tab opens).
 * `version` changes when the member finishes, so a loaded all-time board
 * is fetched again with their day in it.
 */
export default class Boards extends Component<{ data: PageData; version: number }> {
  tab: 'today' | 'all' = 'today';
  leaders: Player[] | null = null;
  loading = false;
  seen = 0;

  oninit(vnode: Mithril.Vnode<{ data: PageData; version: number }, this>) {
    super.oninit(vnode);
    this.seen = this.attrs.version;
  }

  onupdate(vnode: Mithril.VnodeDOM<{ data: PageData; version: number }, this>) {
    super.onupdate(vnode);
    if (this.attrs.version !== this.seen) {
      this.seen = this.attrs.version;
      if (this.leaders) this.load(true);
    }
  }

  load(force = false) {
    if (this.loading || (this.leaders && !force)) return;
    this.loading = true;
    app.store
      .find<Player[]>('who-said-it-players')
      .then((players) => (this.leaders = players))
      .catch((error) => {
        // An empty board ("no scores yet") with an alert that says why.
        this.leaders = [];
        showError(error);
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  rows(): Row[] {
    const day = this.attrs.data.day;
    if (this.tab === 'today') {
      return (day?.top || []).map((row) => ({
        user: row.user,
        main: row.score,
        sub: t('forum.boards.today_sub', { correct: row.correct, count: day?.count || 5 }),
        grid: row.grid,
      }));
    }

    return (this.leaders || []).map((player) => {
      const member = player.user();
      return {
        user: member ? Number(member.id()) : 0,
        main: player.totalScore(),
        sub: t('forum.boards.all_sub', { quizzes: player.quizzes(), best: player.bestStreak() }),
        grid: null,
      };
    });
  }

  view() {
    const rows = this.rows();
    let body: Mithril.Children;
    if (this.tab === 'all' && !this.leaders) body = <LoadingIndicator display="block" size="small" />;
    else if (rows.length) body = <ol className="WsiBoard-list">{rows.map((row, i) => this.row(row, i))}</ol>;
    else body = <p className="WsiBoard-empty">{t(this.tab === 'today' ? 'forum.boards.today_empty' : 'forum.boards.all_empty')}</p>;

    return (
      <section className="WsiBoard">
        <div className="WsiBoard-head">
          <h3 className="WsiSection-title">
            <Icon name="fas fa-trophy" /> {t('forum.boards.title')}
          </h3>
          <div className="WsiTabs" role="tablist">
            {(['today', 'all'] as const).map((tab) => (
              <button
                type="button"
                role="tab"
                aria-selected={this.tab === tab ? 'true' : 'false'}
                className={classList('WsiTab', this.tab === tab && 'is-active')}
                onclick={() => {
                  this.tab = tab;
                  if (tab === 'all') this.load();
                }}
              >
                {t(`forum.boards.${tab}`)}
              </button>
            ))}
          </div>
        </div>
        {body}
      </section>
    );
  }

  row(row: Row, i: number): Mithril.Children {
    const u = user(row.user);

    return (
      <li className={classList('WsiBoard-row', u && app.session.user?.id() === u.id() && 'is-me')}>
        <span className={classList('WsiBoard-rank', i < 3 && `is-medal is-${i + 1}`)}>{i + 1}</span>
        <Avatar user={u} className="Avatar--small" />
        <span className="WsiBoard-who">
          {u ? (
            <Link className="WsiBoard-name" href={app.route('user', { username: u.slug() })}>
              {u.displayName()}
            </Link>
          ) : (
            <span className="WsiBoard-name">{t('forum.stage.deleted')}</span>
          )}
          <span className="WsiBoard-sub">{row.sub}</span>
        </span>
        {row.grid ? (
          <span className="WsiPips" aria-hidden="true">
            {row.grid.split('').map((l) => (
              <span className={`WsiPip WsiPip--${l === '-' ? 'open' : l}`} />
            ))}
          </span>
        ) : null}
        <span className="WsiBoard-score">{row.main}</span>
      </li>
    );
  }
}
