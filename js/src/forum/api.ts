import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import { t } from '../common/text';
import type { Me } from './state';

export interface UserRow {
  id: number;
  username: string;
  displayName: string;
  slug: string;
  avatarUrl: string | null;
}

/** A quote while it's being played: the words and the four faces, nothing more. */
export interface Served {
  id: number;
  position: number;
  excerpt: string;
  choices: number[];
  elapsedMs: number;
  limitMs: number;
}

/** A quote with its answer: one the member answered, or yesterday's. */
export interface Reveal {
  id: number;
  position: number;
  excerpt: string;
  choices: number[];
  author: number | null;
  post: { url: string; title: string } | null;
  withdrawn: boolean;
  chosen: number | null;
  correct: boolean | null;
  points: number;
  bonus: number;
  ms: number | null;
  answered?: number;
  percent?: number | null;
}

export interface PageData {
  date: string;
  nextAt: string;
  canPlay: boolean;
  day: { count: number; players: number; top: { user: number; score: number; correct: number; grid: string }[] } | null;
  me: (Me & { rank: number | null; best: number; total: number; quizzes: number }) | null;
  played: Reveal[];
  current: Served | null;
  allTime: { user: number; total: number; quizzes: number; correct: number; best: number; streak: number }[];
  yesterday: { date: string; questions: Reveal[] } | null;
  users: UserRow[];
  /** Today's quiz is being made by another request right now. */
  pending?: boolean;
}

export function call<T>(method: 'GET' | 'POST', path: string, body?: Record<string, any>): Promise<T> {
  return app.request<T>({
    method,
    url: app.forum.attribute<string>('apiUrl') + '/who-said-it' + path,
    body,
    errorHandler: (error: any) => {
      const code = error?.response?.errors?.[0]?.code;
      const known = ['wsi_closed', 'wsi_withdrawn', 'wsi_no_quiz', 'wsi_making', 'wsi_bad_choice', 'wsi_not_shown'];
      app.alerts.show({ type: 'error' }, known.includes(code) ? t(`forum.errors.${code}`) : t('forum.errors.generic'));
      throw error;
    },
  });
}

/** People arrive as one compact list; the store lets Avatar draw them the usual way. */
export function pushUsers(users: UserRow[]): void {
  for (const user of users) {
    if (app.store.getById('users', String(user.id))) continue;
    app.store.pushObject({
      type: 'users',
      id: String(user.id),
      attributes: { username: user.username, displayName: user.displayName, slug: user.slug, avatarUrl: user.avatarUrl },
    } as any);
  }
}

export function user(id: number | null | undefined): any {
  return id ? app.store.getById('users', String(id)) : null;
}

/** "Tue, Nov 10" for a quiz day (a plain date, so read it as one). */
export function dayLabel(date: string): string {
  const [y, mo, d] = date.split('-').map(Number);
  try {
    return new Intl.DateTimeFormat(app.translator.getLocale() || undefined, { weekday: 'short', month: 'short', day: 'numeric' }).format(
      new Date(y, mo - 1, d)
    );
  } catch {
    return date;
  }
}

export const SQUARES: Record<string, string> = { G: '🟩', Y: '🟨', R: '🟥', V: '⬜', '-': '⬛' };

/** The result as text, to paste anywhere. */
export function shareText(date: string, me: Me, count: number): string {
  const grid = me.grid
    .padEnd(count, '-')
    .slice(0, count)
    .split('')
    .map((l) => SQUARES[l] || '⬛')
    .join('');

  const lines = [
    extractText(t('forum.share.title', { forum: app.forum.attribute('title'), date: dayLabel(date) })),
    `${grid} ${me.correct}/${count} · ${me.score}`,
  ];
  if (me.streak > 1) lines.push(extractText(t('forum.share.streak', { count: me.streak })));
  lines.push(new URL(app.route('who-said-it'), window.location.origin).href);

  return lines.join('\n');
}
