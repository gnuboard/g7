import React, { useState, useCallback } from 'react';
import { Div } from '../basic/Div';
import { H3 } from '../basic/H3';
import { P } from '../basic/P';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  (window as any).G7Core?.t?.(key, params) ?? key;

/**
 * OneTimeSecretPanel — "shown once, capture now" UX for plaintext secrets.
 *
 * Used by:
 *   - gb7-restapi POST /admin/keys (API key plaintext, returned only at issuance)
 *   - gb7-restapi POST /admin/keys/{id}/rotate
 *   - gb7-restapi POST /admin/webhooks (signing secret, same pattern)
 *   - gb7-restapi POST /admin/webhooks/{id}/rotate-secret
 *
 * The secret is rendered AFTER an explicit user click ("Reveal") so it
 * doesn't sit on screen waiting to be shoulder-surfed. A "Copy to
 * clipboard" button hands it to the integrator's secret manager. An
 * "I have saved the key" acknowledgement gate prevents accidentally
 * navigating away before capture.
 *
 * @example
 * // Layout JSON usage (referenced from data_sources response):
 * {
 *   "name": "OneTimeSecretPanel",
 *   "props": {
 *     "value_path":         "data.plaintext",
 *     "title":              "$t:gb7-restapi::admin.keys.one_time_title",
 *     "warning":            "$t:gb7-restapi::admin.keys.one_time_warning",
 *     "copy_button_label":  "$t:gb7-restapi::admin.keys.one_time_copy",
 *     "acknowledge_label":  "$t:gb7-restapi::admin.keys.one_time_acknowledge",
 *     "after_acknowledge": {
 *       "redirect": "/admin/gb7-restapi/keys"
 *     }
 *   }
 * }
 */
export interface OneTimeSecretPanelProps {
  /**
   * The plaintext secret to display. Should be passed via the layout
   * engine's `value_path` resolution from a fresh API response —
   * never bound to anything that could trigger re-render after the
   * acknowledgement (which would re-show a secret the user already
   * captured).
   */
  value: string;

  /** Heading text. */
  title?: string;

  /** Warning paragraph above the secret. */
  warning?: string;

  /** Label for the "Copy to clipboard" button. */
  copyButtonLabel?: string;

  /** Label for the "Reveal" toggle that uncovers the secret. */
  revealButtonLabel?: string;

  /** Label for the "I have saved the key" acknowledgement button. */
  acknowledgeLabel?: string;

  /**
   * What to do after the user acknowledges. `redirect` navigates to
   * the URL; `dispatch` fires a layout-engine action.
   */
  afterAcknowledge?: {
    redirect?: string;
    dispatch?: { handler: string; [k: string]: unknown };
  };

  /**
   * If true, the secret is hidden behind a "Reveal" click. Defaults
   * to true — flip to false only for low-sensitivity values.
   */
  initiallyHidden?: boolean;

  className?: string;
  style?: React.CSSProperties;
}

export const OneTimeSecretPanel: React.FC<OneTimeSecretPanelProps> = ({
  value,
  title,
  warning,
  copyButtonLabel,
  revealButtonLabel,
  acknowledgeLabel,
  afterAcknowledge,
  initiallyHidden = true,
  className = '',
  style,
}) => {
  const [revealed, setRevealed] = useState<boolean>(!initiallyHidden);
  const [copied, setCopied] = useState<boolean>(false);
  const [acknowledged, setAcknowledged] = useState<boolean>(false);

  const resolvedTitle      = title           ?? t('common.one_time_secret.title', { default: 'Save this secret now' });
  const resolvedWarning    = warning         ?? t('common.one_time_secret.warning', { default: 'This is the only time the plaintext value will be shown. Copy it into your secrets manager before continuing.' });
  const resolvedCopy       = copyButtonLabel ?? t('common.one_time_secret.copy', { default: 'Copy to clipboard' });
  const resolvedReveal     = revealButtonLabel ?? t('common.one_time_secret.reveal', { default: 'Reveal' });
  const resolvedAck        = acknowledgeLabel ?? t('common.one_time_secret.acknowledge', { default: 'I have saved this secret' });

  const handleCopy = useCallback(async () => {
    if (!value) return;
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      // Auto-clear the "Copied!" indicator after 2 seconds so the
      // panel doesn't stay in a "you're done" state when the user
      // hasn't actually acknowledged.
      setTimeout(() => setCopied(false), 2000);
    } catch (err) {
      // navigator.clipboard requires HTTPS in modern browsers; fall
      // back to a textarea+execCommand path if it isn't available.
      const ta = document.createElement('textarea');
      ta.value = value;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try {
        document.execCommand('copy');
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
      } finally {
        document.body.removeChild(ta);
      }
    }
  }, [value]);

  const handleAcknowledge = useCallback(() => {
    setAcknowledged(true);
    if (afterAcknowledge?.redirect) {
      window.location.href = afterAcknowledge.redirect;
      return;
    }
    if (afterAcknowledge?.dispatch) {
      const G7Core = (window as any).G7Core;
      G7Core?.ActionDispatcher?.dispatch?.(afterAcknowledge.dispatch, {});
    }
  }, [afterAcknowledge]);

  if (!value) {
    return null;
  }

  // Once acknowledged, the panel collapses to a confirmation message —
  // the secret is gone from the DOM as well as from the user's
  // working memory.
  if (acknowledged) {
    return (
      <Div className={`bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-lg p-6 ${className}`} style={style}>
        <Div className="flex items-center gap-2">
          <Icon name="check-circle" className="w-5 h-5 text-green-600 dark:text-green-400" />
          <P className="text-sm font-medium text-green-800 dark:text-green-200">
            {t('common.one_time_secret.saved', { default: 'Secret saved.' })}
          </P>
        </Div>
      </Div>
    );
  }

  return (
    <Div
      className={`bg-amber-50 dark:bg-amber-900/20 border-2 border-amber-300 dark:border-amber-700 rounded-lg p-6 space-y-4 ${className}`}
      style={style}
      role="alert"
      aria-live="polite"
    >
      <Div className="flex items-start gap-3">
        <Icon name="alert-triangle" className="w-6 h-6 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" />
        <Div className="flex-1 space-y-2">
          <H3 className="text-lg font-semibold text-amber-900 dark:text-amber-100">
            {resolvedTitle}
          </H3>
          <P className="text-sm text-amber-800 dark:text-amber-200">
            {resolvedWarning}
          </P>
        </Div>
      </Div>

      <Div className="bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-600 rounded p-3 font-mono text-sm break-all">
        {revealed ? (
          <span data-testid="secret-value">{value}</span>
        ) : (
          <Button
            type="button"
            onClick={() => setRevealed(true)}
            className="text-blue-600 dark:text-blue-400 hover:underline"
          >
            {resolvedReveal}
          </Button>
        )}
      </Div>

      <Div className="flex flex-wrap gap-2">
        <Button
          type="button"
          onClick={handleCopy}
          disabled={!revealed}
          className="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
        >
          <Icon name={copied ? 'check' : 'copy'} className="w-4 h-4" />
          {copied ? t('common.copied', { default: 'Copied!' }) : resolvedCopy}
        </Button>

        <Button
          type="button"
          onClick={handleAcknowledge}
          disabled={!revealed}
          className="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {resolvedAck}
        </Button>
      </Div>
    </Div>
  );
};

export default OneTimeSecretPanel;
