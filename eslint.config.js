import { defineConfig, globalIgnores } from "eslint/config";
import globals from "globals";
//import babelParser from "babel-eslint";
import js from "@eslint/js";

export default defineConfig([
	{
		ignores: ['node_modules/**', 'vendors/**', 'snappymail/**']
	},
	globalIgnores(["**/*.*", "!/dev/**/*.js", "!/tasks/**/*.js", "!/*.js"]),
	{
		extends: [
			js.configs.recommended
		],
		files: ['dev/**/*.js'],
		languageOptions: {
			globals: {
				...globals.browser,
				rainloopI18N: "readonly",
				rainloopTEMPLATES: "readonly",
				rl: "readonly",
				shortcuts: "readonly",
				progressJs: "readonly",
				openpgp: "readonly",
				CKEDITOR: "readonly",
				Squire: "readonly",
				SquireUI: "readonly",
				ko: "readonly",
				hasher: "readonly",
				Crossroads: "readonly",
				Jua: "readonly",
				BSN: "readonly",
				mailvelope: "readonly",
				IDN: "readonly",
				TurndownService: "readonly",
				marked: "readonly",
			},

//			parser: babelParser,
			ecmaVersion: 11,
			sourceType: "module",
		},

		rules: {
//			...js.configs.recommended.rules,
			"no-cond-assign": 0,
			"no-mixed-spaces-and-tabs": "off",

			"max-len": ["error", 120, 2, {
				ignoreComments: true,
				ignoreUrls: true,
				ignoreTrailingComments: true,
				ignorePattern: "(^\\s*(const|let|var)\\s.+=\\s*require\\s*\\(|^import\\s.+\\sfrom\\s.+;$)",
			}],

			"no-constant-condition": ["error", {
				checkLoops: false,
			}],
		},
	},
]);
