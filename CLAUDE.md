# Working in this repo

## Editing prompts (e.g. `src/Engine/SystemPrompt.php`)

Treat a system prompt as one document, not a changelog. Rules added by patching drift into
conflict, where a later rule contradicts an earlier one (e.g. two different bars for when to
decline a request).

- **Edit holistically.** Re-read the whole prompt before changing it, and again afterward, to
  confirm no two parts disagree. Adjust the whole, don't append a patch.
- **State each rule once.** If concision, tone, or a decision condition shows up in two sections,
  consolidate to one place.
- **Keep the hierarchy clean.** General principles stay general; a specific example lives under the
  specific item it illustrates, never fused onto a general rule.
- **Avoid the "Do A — never B, plus examples" shape.** That em-dash construct buries specifics
  inside a general statement and confuses the prompt. Use plain directives; put subordinate detail
  in a colon list or a separate sentence.
- **Compress.** Information-dense over verbose. Prefer "highest-tier component available
  (Sidebar > Inline > Link)" to a sentence that explains the same idea.
