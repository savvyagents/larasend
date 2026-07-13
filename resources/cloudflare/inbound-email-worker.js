/**
 * Larasend inbound email worker.
 *
 * Cloudflare Email Routing invokes this handler for messages matching the
 * routing rule and it forwards the raw MIME to Larasend for parsing and
 * storage. Deployed automatically by Larasend; LARASEND_INBOUND_URL is set
 * as a plain-text binding at upload time.
 *
 * Throwing on failure defers the message so the sending server retries —
 * emails are never silently dropped when Larasend is unreachable.
 */
export default {
  async email(message, env) {
    const buffer = await new Response(message.raw).arrayBuffer();
    const bytes = new Uint8Array(buffer);

    let binary = "";
    const chunkSize = 0x8000;

    for (let i = 0; i < bytes.length; i += chunkSize) {
      binary += String.fromCharCode(...bytes.subarray(i, i + chunkSize));
    }

    const response = await fetch(env.LARASEND_INBOUND_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        from: message.from,
        to: message.to,
        raw: btoa(binary),
      }),
    });

    if (!response.ok) {
      throw new Error(`Larasend inbound endpoint responded ${response.status}`);
    }
  },
};
