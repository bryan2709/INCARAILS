<script setup>
import {useSession} from './stores/session';import {useRouter} from 'vue-router';
import {ref} from 'vue';
import ImportNotice from './components/ImportNotice.vue';
const s=useSession(),router=useRouter(),exitError=ref('');async function exit(){exitError.value='';try{await s.logout();router.push('/login');}catch(failure){exitError.value=failure.message;}}
</script>
<template>
 <div class="shell" v-if="s.signedIn">
  <aside class="sidebar"><div class="brand"><span class="brand-mark">IR</span><div><strong>INCA RAIL</strong><small>Football console</small></div></div><div class="section-label">EXPLORAR</div>
   <nav><RouterLink to="/competitions">◈ &nbsp; Competiciones</RouterLink><RouterLink to="/team">◫ &nbsp; Equipos</RouterLink><RouterLink to="/players">♟ &nbsp; Jugadores</RouterLink><RouterLink v-if="s.can('administration.manage')" to="/admin">⚙ &nbsp; Administración</RouterLink></nav>
   <div class="user-card"><div class="avatar">{{ s.user?.name?.charAt(0)?.toUpperCase() }}</div><div class="user-info"><strong>{{s.user?.name}}</strong><small>{{s.user?.roles?.join(', ')}}</small></div><button class="icon-btn" title="Cerrar sesión" @click="exit">⎋</button></div>
  </aside>
  <main class="main"><header class="mobile-head"><span class="brand-mark">IR</span><b>Inca Rail</b><button class="btn muted" @click="exit">Salir</button></header><div class="content"><p v-if="exitError" class="alert" role="alert">{{exitError}}</p><ImportNotice/><RouterView/></div></main>
 </div>
 <RouterView v-else />
</template>
