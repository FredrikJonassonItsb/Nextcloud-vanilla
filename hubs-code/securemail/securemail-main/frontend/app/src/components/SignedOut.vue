<template>
  <v-container fluid class="fill-height">
    <v-row justify="center" align="center">
      <v-col cols="12" sm="8" md="6" lg="4">
        <v-card class="mx-auto" elevation="2">
          <v-card-title class="text-center pa-6">
            <v-icon size="48" color="success" class="mb-3">mdi-check-circle</v-icon>
            <h2 class="text-h5">Du har loggats ut</h2>
          </v-card-title>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>

<script setup>
// No logic needed for this simple view
</script>

<style scoped>
.fill-height {
  min-height: 100vh;
}
</style>
