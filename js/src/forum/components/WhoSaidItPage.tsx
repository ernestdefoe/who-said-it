import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Avatar from 'flarum/common/components/Avatar';
import Link from 'flarum/common/components/Link';
import Switch from 'flarum/common/components/Switch';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { t } from '../../common/text';
import { call, dayLabel, pushUsers, shareText, user, type PageData, type Reveal, type Served } from '../api';
import { remember, saveMe, span, type Me } from '../state';

declare const m: import('mithril').Static;
declare const flarum: any;

type Phase = 'loading' | 'error' | 'intro' | 'question' | 'reveal' | 'done';

/**
 * /who-said-it: today's quiz, one quote at a time, then the result to share,
 * the boards and yesterday's answers. A lazy chunk: nothing here loads on
 * other pages.
 *
 * The browser never knows an answer before it's given: a quote arrives as
 * its words and four faces, and the answer only comes back from /answer.
 */
export default class WhoSaidItPage extends Page {
  data: PageData | null = null;
  phase: Phase = 'loading';
  current: Served | null = null;
  reveal: Reveal | null = null;
  shownAt = 0;
  busy = false;
  picked: number | null = null;
  board: 'today' | 'all' = 'today';
  copied = false;
  remindSaving = false;
  ticker = 0;
  retry = 0;
  keys = (e: KeyboardEvent) => this.onKey(e);

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    app.setTitle(extractText(t('forum.page.title')));
    app.setTitleCount(0);
    this.load();
  }

  oncreate(vnode: Mithril.VnodeDOM<any, this>) {
    super.oncreate(vnode);
    document.addEventListener('keydown', this.keys);
    // The countdowns; only this page redraws on a timer, and only once a minute.
    this.ticker = window.setInterval(() => m.redraw(), 30000);
  }

  onremove(vnode: Mithril.VnodeDOM<any, this>) {
    super.onremove(vnode);
    document.removeEventListener('keydown', this.keys);
    window.clearInterval(this.ticker);
    window.clearTimeout(this.retry);
  }

  load() {
    call<PageData>('GET', '')
      .then((data) => {
        this.data = data;
        pushUsers(data.users);
        if (data.me) remember({ me: data.me, count: data.day?.count || 0 });
        if (data.current) this.show(data.current);
        else if (data.me?.finished) this.phase = 'done';
        else this.phase = 'intro';
        // Another visitor is making today's quiz this very moment: ask again shortly.
        if (data.pending) this.retry = window.setTimeout(() => this.load(), 4000);
      })
      .catch(() => (this.phase = 'error'))
      .finally(() => m.redraw());
  }

  view() {
    const { PageStructure, IndexSidebar } = core();

    return (
      <PageStructure className="WsiPage" sidebar={() => <IndexSidebar />}>
        {this.phase === 'loading' ? (
          <LoadingIndicator />
        ) : this.phase === 'error' ? (
          <p className="WsiEmpty">{t('forum.errors.load')}</p>
        ) : (
          this.body()
        )}
      </PageStructure>
    );
  }

  body(): Mithril.Children {
    const data = this.data!;

    return (
      <div className="WsiPage-body">
        {this.head(data)}
        <section className={classList('WsiStage', `WsiStage--${this.phase}`)} aria-live="polite">
          {this.stage(data)}
        </section>
        <div className="WsiBelow">
          {this.boards(data)}
          {this.yesterday(data)}
        </div>
      </div>
    );
  }

  head(data: PageData) {
    const me = data.me;

    return (
      <header className="WsiHead">
        <div className="WsiHead-mark" aria-hidden="true">
          “
        </div>
        <div className="WsiHead-text">
          <h1 className="WsiHead-title">{t('forum.page.title')}</h1>
          <p className="WsiHead-lead">{t('forum.page.lead')}</p>
        </div>
        <ul className="WsiHead-stats">
          <li>
            <Icon name="far fa-calendar" /> {dayLabel(data.date)}
          </li>
          {data.day ? (
            <li>
              <Icon name="fas fa-user-group" /> {t('forum.page.players', { count: data.day.players })}
            </li>
          ) : null}
          {me ? (
            <li className={classList('WsiHead-streak', me.streak > 0 && 'is-lit')}>
              <Icon name="fas fa-fire" /> {t('forum.page.streak', { count: me.streak })}
            </li>
          ) : null}
        </ul>
      </header>
    );
  }

  stage(data: PageData): Mithril.Children {
    if (!data.day) {
      return data.pending ? (
        <div className="WsiNote">
          <Icon name="fas fa-hourglass-half" />
          <h2>{t('forum.card.soon')}</h2>
          <LoadingIndicator display="inline" size="small" />
        </div>
      ) : (
        <div className="WsiNote">
          <Icon name="fas fa-hourglass-half" />
          <h2>{t('forum.stage.none_title')}</h2>
          <p>{t('forum.stage.none_text')}</p>
        </div>
      );
    }

    if (!app.session.user) {
      return (
        <div className="WsiNote">
          <Icon name="fas fa-quote-left" />
          <h2>{t('forum.stage.guest_title', { count: data.day.count })}</h2>
          <p>{t('forum.stage.guest_text')}</p>
          <Button className="Button Button--primary WsiBig" onclick={() => app.modal.show(() => import('flarum/forum/components/LogInModal'))}>
            {t('forum.stage.log_in')}
          </Button>
        </div>
      );
    }

    if (!data.canPlay) {
      return (
        <div className="WsiNote">
          <Icon name="fas fa-lock" />
          <p>{t('forum.stage.no_permission')}</p>
        </div>
      );
    }

    if (this.phase === 'question' || this.phase === 'reveal') return this.play(data);
    if (this.phase === 'done') return this.done(data);

    return this.intro(data);
  }

  intro(data: PageData) {
    const count = data.day!.count;
    const started = (data.me?.answered || 0) > 0;

    return (
      <div className="WsiIntro">
        {this.pips(data, null)}
        <h2 className="WsiIntro-title">{started ? t('forum.stage.continue_title') : t('forum.stage.intro_title', { count })}</h2>
        <ul className="WsiRules">
          <li>
            <span className="WsiRules-icon">
              <Icon name="fas fa-quote-left" />
            </span>
            {t('forum.stage.rule_quotes', { count })}
          </li>
          <li>
            <span className="WsiRules-icon">
              <Icon name="fas fa-bolt" />
            </span>
            {t('forum.stage.rule_speed')}
          </li>
          <li>
            <span className="WsiRules-icon">
              <Icon name="fas fa-fire" />
            </span>
            {t('forum.stage.rule_streak')}
          </li>
        </ul>
        <Button
          className="Button Button--primary WsiBig"
          icon={started ? 'fas fa-forward' : 'fas fa-play'}
          loading={this.busy}
          onclick={() => this.next()}
        >
          {started ? t('forum.stage.continue', { done: data.me!.answered, count }) : t('forum.stage.start')}
        </Button>
        {this.remindSwitch(data)}
      </div>
    );
  }

  /** The quote, the clock and the four faces; after an answer, the same with the verdict. */
  play(data: PageData) {
    const q: Served | Reveal = this.reveal || this.current!;
    const r = this.reveal;
    const count = data.day!.count;
    const remaining = this.current ? Math.max(0, this.current.limitMs - this.current.elapsedMs) : 0;

    return (
      <div className="WsiPlay">
        <div className="WsiPlay-top">
          {this.pips(data, r ? null : q.position)}
          <span className="WsiPlay-count">{t('forum.stage.of', { n: q.position, count })}</span>
        </div>

        {!r && this.current ? (
          <div className="WsiTimer" title={extractText(t('forum.stage.speed_hint'))}>
            <Icon name="fas fa-bolt" />
            <span className="WsiTimer-track">
              <span
                key={`timer-${this.current.id}`}
                className="WsiTimer-fill"
                style={{
                  animationDuration: `${this.current.limitMs}ms`,
                  animationDelay: `-${Math.min(this.current.elapsedMs, this.current.limitMs)}ms`,
                }}
              />
            </span>
            <span className="WsiTimer-label">{remaining > 0 ? t('forum.stage.speed') : t('forum.stage.no_speed')}</span>
          </div>
        ) : null}

        {/* Keyed alone in its wrapper so each new quote replays its entrance (siblings must be all keyed or none). */}
        <div className="WsiQuote-wrap">
          {[
            <blockquote className="WsiQuote" key={`q-${q.id}`}>
              <p>{q.excerpt}</p>
            </blockquote>,
          ]}
        </div>

        <p className="WsiPlay-ask">{r ? '' : t('forum.stage.ask')}</p>

        <div className="WsiChoices" role="group" aria-label={extractText(t('forum.stage.ask'))}>
          {q.choices.map((id, i) => this.choice(id, i, r))}
        </div>

        {r ? this.verdict(r, data) : null}
      </div>
    );
  }

  choice(id: number, i: number, r: Reveal | null) {
    const u = user(id);
    const isAuthor = r && r.author === id;
    const isPicked = (r ? r.chosen : this.picked) === id;
    const state = r ? (isAuthor ? 'right' : isPicked ? 'wrong' : 'dim') : isPicked ? 'picked' : '';

    return (
      <button
        type="button"
        className={classList('WsiChoice', state && `is-${state}`)}
        disabled={!!r || this.busy || !id}
        aria-pressed={isPicked ? 'true' : 'false'}
        onclick={() => this.answer(id)}
      >
        <span className="WsiChoice-key" aria-hidden="true">
          {i + 1}
        </span>
        <Avatar user={u} className="WsiChoice-avatar" />
        <span className="WsiChoice-names">
          <span className="WsiChoice-name">{u ? u.displayName() : t(id ? 'forum.stage.deleted' : 'forum.stage.left')}</span>
          {u ? <span className="WsiChoice-user">@{u.username()}</span> : null}
        </span>
        {r && isAuthor ? <Icon name="fas fa-check" className="WsiChoice-mark" /> : null}
        {r && isPicked && !isAuthor ? <Icon name="fas fa-xmark" className="WsiChoice-mark" /> : null}
      </button>
    );
  }

  verdict(r: Reveal, data: PageData) {
    const author = user(r.author);
    const last = this.isLast(data);

    return (
      <div className={classList('WsiVerdict', r.correct ? 'is-right' : 'is-wrong')}>
        <div className="WsiVerdict-line">
          <span className="WsiVerdict-badge">
            <Icon name={r.correct ? 'fas fa-check' : 'fas fa-xmark'} />
          </span>
          <span className="WsiVerdict-text">
            <strong>{r.correct ? t('forum.stage.right') : t('forum.stage.wrong', { name: author ? author.displayName() : '?' })}</strong>
            {r.correct ? (
              <span className="WsiVerdict-points">
                {t('forum.stage.points', { points: r.points })}
                {r.bonus > 0 ? <span className="WsiVerdict-bonus"> {t('forum.stage.bonus', { bonus: r.bonus })}</span> : null}
              </span>
            ) : null}
          </span>
        </div>
        {r.post ? (
          <a className="WsiVerdict-source" href={r.post.url} onclick={(e: MouseEvent) => this.follow(e, r.post!.url)}>
            <Icon name="far fa-comment" /> <span>{t('forum.stage.source', { title: r.post.title })}</span>
          </a>
        ) : null}
        <Button
          className="Button Button--primary WsiBig"
          icon={last ? 'fas fa-flag-checkered' : 'fas fa-arrow-right'}
          loading={this.busy}
          onclick={() => this.next()}
        >
          {last ? t('forum.stage.see_result') : t('forum.stage.next')}
        </Button>
      </div>
    );
  }

  done(data: PageData) {
    const me = data.me!;
    const count = data.day!.count;

    return (
      <div className="WsiDone">
        <div className="WsiDone-score">
          <span className="WsiDone-points">{me.score}</span>
          <span className="WsiDone-label">{t('forum.done.points')}</span>
        </div>
        <p className="WsiDone-summary">
          {t('forum.done.summary', { correct: me.correct, count })}
          {me.rank ? <> · {t('forum.done.rank', { rank: me.rank, count: data.day!.players })}</> : null}
        </p>
        <div className="WsiDone-grid" aria-label={extractText(t('forum.done.grid_label'))}>
          {me.grid
            .padEnd(count, '-')
            .slice(0, count)
            .split('')
            .map((letter) => (
              <span className={`WsiSquare WsiSquare--${letter === '-' ? 'open' : letter}`} />
            ))}
        </div>
        {me.streak > 0 ? (
          <p className="WsiDone-streak">
            <Icon name="fas fa-fire" /> {t('forum.done.streak', { count: me.streak })}
          </p>
        ) : null}
        <div className="WsiDone-actions">
          <Button className="Button Button--primary WsiBig" icon={this.copied ? 'fas fa-check' : 'far fa-copy'} onclick={() => this.copy(data)}>
            {this.copied ? t('forum.done.copied') : t('forum.done.copy')}
          </Button>
        </div>
        <p className="WsiDone-next">
          <Icon name="far fa-clock" /> {t('forum.done.next', { time: span(Date.parse(data.nextAt) - Date.now()) })}
        </p>
        {this.remindSwitch(data)}
        {data.played.length ? (
          <div className="WsiRecap">
            <h3 className="WsiSection-title">{t('forum.done.answers')}</h3>
            {data.played.map((r) => this.recap(r, true))}
          </div>
        ) : null}
      </div>
    );
  }

  /** A quote with its answer, as a compact row: today's recap and yesterday's list. */
  recap(r: Reveal, mine: boolean) {
    const author = user(r.author);
    if (r.withdrawn) {
      return (
        <div className="WsiRecapRow is-withdrawn">
          <span className="WsiRecapRow-n">{r.position}</span>
          <p className="WsiRecapRow-quote">{t('forum.recap.withdrawn')}</p>
        </div>
      );
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
            {mine ? (
              <span className="WsiRecapRow-result">
                <Icon name={r.correct ? 'fas fa-check' : 'fas fa-xmark'} />{' '}
                {r.correct ? t('forum.stage.points', { points: r.points }) : t('forum.recap.missed')}
              </span>
            ) : r.percent !== null && r.percent !== undefined ? (
              <span className="WsiRecapRow-result">{t('forum.recap.percent', { percent: r.percent })}</span>
            ) : null}
            {r.post ? (
              <a className="WsiRecapRow-link" href={r.post.url} onclick={(e: MouseEvent) => this.follow(e, r.post!.url)}>
                {t('forum.recap.read')} <Icon name="fas fa-arrow-right" />
              </a>
            ) : null}
          </div>
        </div>
      </div>
    );
  }

  pips(data: PageData, at: number | null) {
    const count = data.day!.count;
    const grid = (data.me?.grid || '').padEnd(count, '-');

    return (
      <span className="WsiPips WsiPips--big" aria-hidden="true">
        {grid
          .slice(0, count)
          .split('')
          .map((letter, i) => (
            <span className={classList('WsiPip', `WsiPip--${letter === '-' ? 'open' : letter}`, at === i + 1 && 'is-current')} />
          ))}
      </span>
    );
  }

  remindSwitch(data: PageData) {
    if (!data.me) return null;
    return (
      <div className="WsiRemind">
        <Switch state={data.me.remind} loading={this.remindSaving} onchange={(on: boolean) => this.setRemind(on)}>
          {t('forum.settings.remind')}
        </Switch>
      </div>
    );
  }

  boards(data: PageData) {
    const rows =
      this.board === 'today'
        ? (data.day?.top || []).map((row) => ({
            user: row.user,
            main: row.score,
            sub: t('forum.boards.today_sub', { correct: row.correct, count: data.day?.count || 5 }),
            grid: row.grid,
          }))
        : data.allTime.map((row) => ({
            user: row.user,
            main: row.total,
            sub: t('forum.boards.all_sub', { quizzes: row.quizzes, best: row.best }),
            grid: null as string | null,
          }));

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
                aria-selected={this.board === tab ? 'true' : 'false'}
                className={classList('WsiTab', this.board === tab && 'is-active')}
                onclick={() => (this.board = tab)}
              >
                {t(`forum.boards.${tab}`)}
              </button>
            ))}
          </div>
        </div>
        {rows.length ? (
          <ol className="WsiBoard-list">
            {rows.map((row, i) => {
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
            })}
          </ol>
        ) : (
          <p className="WsiBoard-empty">{this.board === 'today' ? t('forum.boards.today_empty') : t('forum.boards.all_empty')}</p>
        )}
      </section>
    );
  }

  yesterday(data: PageData) {
    const y = data.yesterday;

    return (
      <section className="WsiYesterday">
        <h3 className="WsiSection-title">
          <Icon name="fas fa-clock-rotate-left" /> {t('forum.yesterday.title')}
          {y ? <span className="WsiSection-date">{dayLabel(y.date)}</span> : null}
        </h3>
        {y && y.questions.length ? (
          <div className="WsiRecap">{y.questions.map((r) => this.recap(r, false))}</div>
        ) : (
          <p className="WsiBoard-empty">{t('forum.yesterday.empty')}</p>
        )}
      </section>
    );
  }

  // ── actions ───────────────────────────────────────────────────

  show(q: Served) {
    this.current = q;
    this.reveal = null;
    this.picked = null;
    this.shownAt = Date.now() - q.elapsedMs;
    this.phase = 'question';
  }

  isLast(data: PageData): boolean {
    return (data.me?.grid || '').indexOf('-') === -1 || (data.me?.answered || 0) >= data.day!.count;
  }

  next() {
    if (this.busy || !this.data) return;
    this.busy = true;

    call<{ current: Served | null; me: PageData['me']; users: PageData['users'] }>('POST', '/next')
      .then((res) => {
        pushUsers(res.users);
        this.data!.me = res.me;
        remember({ me: res.me! });
        if (res.current) {
          this.show(res.current);
        } else {
          this.current = null;
          this.reveal = null;
          this.phase = 'done';
          // The boards and the recap now include this member's day.
          return call<PageData>('GET', '').then((data) => {
            this.data = data;
            pushUsers(data.users);
          });
        }
      })
      .catch(() => {})
      .finally(() => {
        this.busy = false;
        m.redraw();
        if (this.phase === 'question')
          requestAnimationFrame(() => (document.querySelector('.WsiQuote') as HTMLElement | null)?.scrollIntoView?.({ block: 'nearest' }));
      });
  }

  answer(choice: number) {
    if (this.busy || this.phase !== 'question' || !this.current) return;
    this.busy = true;
    this.picked = choice;

    call<{ reveal: Reveal; me: PageData['me']; users: PageData['users'] }>('POST', '/answer', { question: this.current.id, choice })
      .then((res) => {
        pushUsers(res.users);
        this.reveal = res.reveal;
        this.data!.me = res.me;
        this.data!.played = [...this.data!.played.filter((p) => p.id !== res.reveal.id), res.reveal];
        remember({ me: res.me! });
        this.phase = 'reveal';
        if (navigator.vibrate && res.reveal.correct === false) navigator.vibrate(40);
      })
      .catch(() => {
        this.picked = null;
        // Withdrawn or closed meanwhile: move on to whatever comes next.
        this.current = null;
        this.phase = 'intro';
      })
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }

  copy(data: PageData) {
    const text = shareText(data.date, data.me!, data.day!.count);
    const done = () => {
      this.copied = true;
      m.redraw();
      setTimeout(() => {
        this.copied = false;
        m.redraw();
      }, 2500);
    };

    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(text).then(done, () => fallbackCopy(text) && done());
    } else if (fallbackCopy(text)) {
      done();
    }
  }

  setRemind(on: boolean) {
    this.remindSaving = true;
    saveMe({ remind: on })
      .then((res) => {
        if (this.data?.me) this.data.me.remind = res.remind;
      })
      .catch(() => {})
      .finally(() => {
        this.remindSaving = false;
        m.redraw();
      });
  }

  /** A link to a post on this forum opens in the app; anything else as a link would. */
  follow(e: MouseEvent, url: string) {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    const base = app.forum.attribute<string>('baseUrl');
    if (url.indexOf(base) !== 0) return;
    e.preventDefault();
    m.route.set(url.slice(base.length) || '/');
  }

  /** 1–4 pick a face, Enter goes on — unless the member is typing somewhere. */
  onKey(e: KeyboardEvent) {
    if (e.metaKey || e.ctrlKey || e.altKey) return;
    const el = e.target as HTMLElement | null;
    if (el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName))) return;

    if (this.phase === 'question' && this.current && /^[1-4]$/.test(e.key)) {
      const id = this.current.choices[Number(e.key) - 1];
      if (id) {
        e.preventDefault();
        this.answer(id);
      }
    } else if (this.phase === 'reveal' && e.key === 'Enter' && !(el && el.tagName === 'BUTTON')) {
      e.preventDefault();
      this.next();
    }
  }
}

/** Core page parts, lazy in Flarum 2: loaded through the registry before the page draws. */
let parts: { PageStructure: any; IndexSidebar: any } | null = null;

export async function loadCore(): Promise<void> {
  if (parts) return;
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  parts = { PageStructure: structure.default || structure, IndexSidebar: sidebar.default || sidebar };
}

function core(): { PageStructure: any; IndexSidebar: any } {
  return parts!;
}

function fallbackCopy(text: string): boolean {
  const area = document.createElement('textarea');
  area.value = text;
  area.setAttribute('readonly', '');
  area.style.position = 'fixed';
  area.style.opacity = '0';
  document.body.appendChild(area);
  area.select();
  let ok = false;
  try {
    ok = document.execCommand('copy');
  } catch {
    ok = false;
  }
  area.remove();
  return ok;
}
