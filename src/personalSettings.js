/**
 * SPDX-FileCopyrightText: 2026 Marcel Meyer <gh@grenzallee.eu>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createApp } from 'vue'
import PersonalSettings from './PersonalSettings.vue'

const app = createApp(PersonalSettings)
app.mount('#immich-personal-settings')
