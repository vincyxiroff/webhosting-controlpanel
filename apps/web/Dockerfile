FROM node:22-alpine
WORKDIR /app
COPY package.json package.json
RUN npm install
COPY . .
ARG NEXT_PUBLIC_API_URL=http://localhost:8080/v1
ARG NEXT_PUBLIC_WS_URL=ws://localhost:8081
ENV NEXT_PUBLIC_API_URL=$NEXT_PUBLIC_API_URL
ENV NEXT_PUBLIC_WS_URL=$NEXT_PUBLIC_WS_URL
EXPOSE 3000
CMD ["npm", "run", "dev"]
