import app from 'flarum/forum/app';

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
export function remember(patch: Partial<Today> & { me?: Partial<Me> | null }): void {
  const state = today();
  if (!state) return;
  const { me, ...rest } = patch;
  Object.assign(state, rest);
  if (me && state.me) Object.assign(state.me, me);
}

/** Save the member's own choices (reminder, opt-out). */
export function saveMe(body: { remind?: boolean; optedOut?: boolean }): Promise<{ remind: boolean; optedOut: boolean }> {
  return app
    .request<{ remind: boolean; optedOut: boolean }>({
      method: 'POST',
      url: app.forum.attribute<string>('apiUrl') + '/who-said-it/me',
      body,
    })
    .then((res) => {
      remember({ me: res });
      return res;
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
