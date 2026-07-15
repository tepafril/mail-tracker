/**
 * Ambient declaration for `process.env.*`, which is replaced at build time by webpack
 * DefinePlugin (see config.ts); this shape just satisfies the type checker for the source.
 */
declare const process: {
  env: Record<string, string | undefined>;
};
