WITH live_orders AS (
    SELECT created_at, last_error FROM billing_orders
    WHERE provider = 'paypal' AND provider_environment = 'live'
      AND state IN ('pending', 'processing', 'review_required', 'failed')
), deferred_subscriptions AS (
    SELECT s.id, s.provider_id, s.merchant_scope
    FROM billing_subscriptions AS s
    WHERE s.provider = 'paypal' AND s.provider_environment = 'live'
      AND s.state = 'pending' AND s.provider_status = 'APPROVAL_PENDING'
      AND s.resolution_required AND s.paypal_retry_reason = 'SUBSCRIPTION_NOT_FOUND'
      AND s.paypal_retry_at > now()
      AND NOT EXISTS (SELECT 1 FROM billing_payments AS p WHERE p.subscription_id = s.id)
), live_inbox AS (
    SELECT i.created_at, i.state, i.last_error,
        (i.event_type = 'BILLING.SUBSCRIPTION.CREATED'
         AND i.last_error IS NOT DISTINCT FROM 'PAYPAL_SUBSCRIPTION_NOT_FOUND'
         AND COALESCE(i.paypal_retry_at > now(), false)
         AND EXISTS (SELECT 1 FROM deferred_subscriptions AS s
                     WHERE s.provider_id = i.object_id AND s.merchant_scope = i.merchant_scope)) AS deferred
    FROM billing_inbox AS i
    WHERE provider = 'paypal' AND provider_environment = 'live'
      AND state IN ('pending', 'processing')
)
SELECT
    (SELECT count(*) FROM live_orders WHERE last_error IS NOT NULL
       AND created_at <= now() - interval '30 minutes') AS aged_order_reviews,
    (SELECT COALESCE(GREATEST(0, floor(extract(epoch FROM now() - min(created_at)))), 0)::bigint
       FROM live_orders) AS oldest_open_order_seconds,
    (SELECT COALESCE(GREATEST(0, floor(extract(epoch FROM now() - min(created_at)))), 0)::bigint
       FROM live_inbox WHERE NOT deferred) AS oldest_pending_inbox_seconds,
    (SELECT count(*) FROM live_inbox WHERE state = 'pending' AND last_error IS NOT NULL
       AND NOT deferred) AS inbox_errors,
    (SELECT count(*) FROM live_inbox WHERE state = 'pending' AND deferred) AS inbox_deferred,
    (SELECT count(*) FROM deferred_subscriptions) AS subscriptions_deferred,
    (SELECT count(*) FROM billing_subscriptions
       WHERE provider = 'paypal' AND provider_environment = 'live' AND resolution_required
         AND paypal_retry_reason = 'SUBSCRIPTION_NOT_FOUND_PRIORITY') AS subscriptions_priority,
    -- Retry timing controls when the next read runs, not whether the last failure is resolved.
    (SELECT count(*) FROM billing_subscriptions AS s
       WHERE s.provider = 'paypal' AND s.provider_environment = 'live' AND s.resolution_required
         AND s.paypal_retry_reason IS NOT NULL
         AND NOT EXISTS (SELECT 1 FROM deferred_subscriptions AS d WHERE d.id = s.id)) AS subscription_errors;
