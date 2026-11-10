import app from 'flarum/common/app';

/** A translation under ernestdefoe-who-said-it.{key}. */
export function t(key: string, params: Record<string, any> = {}): any {
  return app.translator.trans(`ernestdefoe-who-said-it.${key}`, params);
}
