import app from 'flarum/forum/app';
import { t } from '../common/text';

export interface Me {
  score: number;
  correct: number;
  answered: number;
  grid: string;
  finished: boolean;
  streak: number;
  remind: boolean;
  optedOut: boolean;
  rank?: number | null;
  best?: number;
  total?: number;
  quizzes?: number;
}

/** The `whoSaidIt` forum attribute: today's quiz and, for a member, their day and choices. */
export interface Today {
  date: string;
  nextAt: string;
  count: number;
  canPlay: boolean;
  me: Me | null;
}

export function today(): Today | null {
  return app.forum.attribute<Today | null>('whoSaidIt') || null;
}

/** Keep the card and the settings switches in step with what the page just did. */
export function remember(patch: Partial<Omit<Today, 'me'>> & { me?: Partial<Me> | null }): void {
  const state = today();
  if (!state) return;
  const { me, ...rest } = patch;
  Object.assign(state, rest);
  if (me && state.me) Object.assign(state.me, me);
}

/** Errors the server explains, in the member's words; anything else is "something went wrong". */
const KNOWN = ['wsi_closed', 'wsi_withdrawn', 'wsi_no_quiz', 'wsi_making', 'wsi_bad_choice', 'wsi_not_shown'];

/** Tell the member a request failed. Every catch calls this: nothing fails silently. */
export function showError(error: any): void {
  const code = error?.response?.errors?.[0]?.code;
  app.alerts.show({ type: 'error' }, KNOWN.includes(code) ? t(`forum.errors.${code}`) : t('forum.errors.generic'));
}

/** Rejects instead of alerting: the caller decides what the member sees (showError). */
export const rethrow = (error: any) => {
  throw error;
};

/** Save the member's own choices (reminder, opt-out) on their `who-said-it-players` record. */
export function saveMe(body: { remind?: boolean; optedOut?: boolean }): Promise<{ remind: boolean; optedOut: boolean }> {
  const id = String(app.session.user!.id());

  return app
    .request<{ data: { attributes: { remind: boolean; optedOut: boolean } } }>({
      method: 'PATCH',
      url: `${app.forum.attribute<string>('apiUrl')}/who-said-it-players/${id}`,
      body: { data: { type: 'who-said-it-players', id, attributes: body } },
      errorHandler: rethrow,
    })
    .then((res) => {
      const { remind, optedOut } = res.data.attributes;
      remember({ me: { remind, optedOut } });
      return { remind, optedOut };
    });
}

/** "5h 12m", "12m", "45s". */
export function span(ms: number): string {
  const s = Math.max(0, Math.round(ms / 1000));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  if (h) return `${h}h ${String(m).padStart(2, '0')}m`;
  if (m) return `${m}m`;
  return `${s}s`;
}
